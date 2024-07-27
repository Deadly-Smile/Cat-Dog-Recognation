<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\AIModel;
use Illuminate\Http\Request;
use App\Models\AIModelParameter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Http\Requests\CreateOrUpdateModelRequest;
use Symfony\Component\Process\Exception\ProcessFailedException;


class ModelController extends Controller
{

    // todo: implement parameters array insertion and tag attachment
    public function createOrUpdateModel(CreateOrUpdateModelRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = auth()->user();
        $model = null;
        $id = $data['id'] ?? null;
        $existingIds = [];

        if ($id != null) {
            $model = AIModel::find($data['id']);
            if (!$model) {
                return response()->json(['error' => 'model not found'], 404);
            }
            if ($user->id !== $model->user_id && !in_array($user->role->slug, ['admin', 'master'])) {
                return response()->json(['error' => 'unauthorized'], 401);
            }
            $model->name = $data['name'];
            $model->save();
        } else {
            $model = AIModel::create([
                'name' => $data['name'],
                'user_id' => auth()->user()->id,
                'status' => 'pending',
            ]);
        }

        $status_code = 200;
        // dd($data['parameters']);
        foreach ($data['parameters'] as $parameter) {
            $result = $this->createOrUpdateParameter($parameter, $model->id);
            if ($status_code === 200 && $result['status'] === 201) {
                $status_code = $result['status'];
            }
            if ($result['status'] >= 200) {
                $existingIds[] = $result['id'];
            } else {
                $model->delete();
                return response()->json(['message' => 'Error occurred while creating parameter'], 400);
            }
        }

        $this->deleteUnusedParameters($model, $existingIds);

        return response()->json(['model' => $model, 'parameters' => $model->parameters()->get()], $status_code);
    }

    private function createOrUpdateParameter($data, $modelId)
    {
        // dd($data);
        $parameter = isset($data['id']) ? AIModelParameter::findOrFail($data['id']) : null;
        $status_code = 0;
        $error_message = '';

        if (!$parameter) {
            // Create new parameter
            $parameter = AIModelParameter::create([
                'model_id' => $modelId,
                'parameter_name' => $data['parameter_name'],
                'is_file' => $data['is_file'],
                'is_default' => $data['is_default'],
                'is_required' => $data['is_required'],
            ]);
            $status_code = 201;
        } else {
            // Update existing parameter
            $parameter->update([
                'parameter_name' => $data['parameter_name'],
                'is_file' => $data['is_file'],
                'is_default' => $data['is_default'],
                'is_required' => $data['is_required'],
            ]);
            $status_code = 200;
        }
        if ($data['is_default']) {
            if ($data['is_file']) {
                if (isset($data['file'])) {
                    if (is_object($data['file'])) {
                        // Delete existing file if any
                        if ($parameter->file) {
                            Storage::disk('public')->delete($parameter->file);
                        }
                        $fileName = 'file_' . $parameter->id . "." . $data['file']->getClientOriginalExtension();
                        $path = $data['file']->storeAs('parameters', $fileName, 'public');
                        $parameter->file = $path;
                        $parameter->text = null;
                    } else {
                        // If it's a string, assume it's an existing file path
                        $parameter->file = $data['file'];
                        $parameter->text = null;
                    }
                } else {
                    $status_code = 422;
                    $error_message = 'File is required but not provided';
                }
            } else {
                $parameter->text = $data['text'];
                $parameter->file = null;
            }
        }

        $parameter->save();
        return ['id' => $parameter->id, 'status' => $status_code, 'error' => $error_message];
    }


    private function deleteUnusedParameters($model, $existingIds)
    {
        $parametersToDelete = $model->parameters()->whereNotIn('id', $existingIds)->get();
        foreach ($parametersToDelete as $parameter) {
            if ($parameter->file) {
                Storage::disk('public')->delete($parameter->file);
            }
            $parameter->delete();
        }
    }

    public function addParameter(Request $request, $id): JsonResponse
    {
        $model = AIModel::findOrFail($id);
        $user = auth()->user();
        if ($user->id !== $model->user_id && !in_array($user->role->slug, ['admin', 'master'])) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'parameter_name' => 'required|string|max:255',
            'is_file' => 'required|boolean',
            'file' => 'required_if:is_file,true|file',
            'text' => 'required_if:is_file,false|string',
            'is_default' => 'required_if:is_required,false|boolean',
            'is_required' => 'required_if:is_default,false|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'validation error', 'errors' => $validator->errors()], 422);
        }
        return response()->json([], $this->createOrUpdateParameter($request, $id));
    }

    // tested
    public function uploadModel(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'model' => 'required|file|max:52428800',
            'name' => 'required|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'validation error', 'errors' => $validator->errors()], 422);
        }

        $extentions = ['keras', 'h5', 'pb', 'tflite', 'pth', 'pt', 'onnx', 'caffemodel', 'prototxt', 'params', 'json', 'bin', 'pkl', 'model', 'txt'];
        $extention = $request->file('model')->getClientOriginalExtension();
        if (!in_array($extention, $extentions)) {
            return response()->json(['error' => 'validation error', 'errors' => ['model' => ['The model field must be a file of type: ' . implode(', ', $extentions) . '.']]], 422);
        }

        $user = auth()->user();
        $model = AIModel::find($request['id']);

        if ($model) {
            if ($model->user_id !== $user->id && (Role::findOrFail($user->role_id)->slug !== 'admin' || Role::findOrFail($user->role_id)->slug !== 'master')) {
                return response()->json(['error' => 'unauthorized'], 401);
            }

            $fileName = 'model_' . $model->id . "." . $request->file('model')->getClientOriginalExtension();
            $path = $request->file('model')->storeAs('models', $fileName, 'public');

            $model->name = $request->name;
            $model->model_file = $path;
            $model->save();
            return response()->json(['message' => 'model uploaded', 'model' => $model], 200);
        } else {
            $model = AIModel::create([
                'user_id' => $user->id,
                'name' => $request->name,
                // 'model_file' => $request->file('model')->store('models', 'public'),
                'status' => 'pending',
            ]);

            $fileName = 'model_' . $user->id . "." . $request->file('model')->getClientOriginalExtension();
            $path = $request->file('model')->storeAs('models', $fileName, 'public');

            $model->model_file = $path;
            $model->save();
            return response()->json(['message' => 'model uploaded', 'model' => $model], 201);
        }
    }

    // tested
    public function uploadTestImage(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'validation error', 'errors' => $validator->errors()], 422);
        }

        // in laravel-11, you have to run 'php artisan storage:link' for accessing from web possible
        // return response()->json(['image' => Storage::url($request->file('image')->store('images', 'public'))]);

        $model = AIModel::findOrFail($id);
        $user = auth()->user();
        if (!$model) {
            return response()->json(['error' => 'model not found'], 404);
        } else if ($user->id !== $model->user_id && (Role::findOrFail($user->role_id)->slug !== 'admin' || Role::findOrFail($user->role_id)->slug !== 'master')) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        if ($model->test_file && Storage::exists($model->test_file)) {
            Storage::delete($model->test_file);
        }

        $model->test_file = $request->file('image')->store('images', 'public');
        $model->save();
        return response()->json(['message' => 'test image uploaded', 'image' => Storage::url($model->test_file)], 200);
    }

    // tested
    public function uploadPythonScript(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'script' => 'required|file|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'validation error', 'errors' => $validator->errors()], 422);
        }

        $file = $request->file('script');
        if ($file->getClientOriginalExtension() !== 'py') {
            return response()->json(['error' => 'validation error', 'errors' => ['script' => ['The script field must be a file of type: py.']]], 422);
        }

        $model = AIModel::findOrFail($id);
        $user = auth()->user();
        if (!$model) {
            return response()->json(['error' => 'model not found'], 404);
        } else if ($user->id !== $model->user_id && (Role::findOrFail($user->role_id)->slug !== 'admin' || Role::findOrFail($user->role_id)->slug !== 'master')) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        if ($model->script_file && Storage::exists($model->script_file)) {
            Storage::delete($model->script_file);
        }

        $fileName = 'script_' . $id . '.py';
        $path = $file->storeAs('scripts', $fileName, 'public');

        $model->script_file = $path;
        $model->save();
        $output = $this->testScript($model->model_file, $model->test_file, $model->script_file);
        return $output;
    }

    // need testing
    public function uploadDocumentation(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'doc' => 'required|file|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'validation error', 'errors' => $validator->errors()], 422);
        }

        $file = $request->file('doc');
        if ($file->getClientOriginalExtension() !== 'md') {
            return response()->json(['error' => 'validation error', 'errors' => ['doc' => ['The doc field must be a file of type: md.']]], 422);
        }

        $model = AIModel::findOrFail($id);
        $user = auth()->user();
        if (!$model) {
            return response()->json(['error' => 'model not found'], 404);
        } else if ($user->id !== $model->user_id && (Role::findOrFail($user->role_id)->slug !== 'admin' || Role::findOrFail($user->role_id)->slug !== 'master')) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        if ($model->doc_file && Storage::exists($model->doc_file)) {
            Storage::delete($model->doc_file);
        }

        $fileName = 'doc_' . $id . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('docs', $fileName, 'public');

        $model->doc_file = $path;
        $model->save();
        return response()->json(['message' => 'documentation uploaded'], 200);
    }

    // need testing
    public function deleteModel($id): JsonResponse
    {
        $model = AIModel::find($id);
        $user = auth()->user();
        if ($user->id !== $model->user_id && (Role::findOrFail($user->role_id)->slug !== 'admin' || Role::findOrFail($user->role_id)->slug !== 'master')) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $model->delete();
        return response()->json(['message' => 'model deleted'], 200);
    }

    public function updateName($id, $name): JsonResponse
    {
        $validator = Validator::make(['name' => $name], [
            'name' => 'required|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'validation error', 'errors' => $validator->errors()], 422);
        }
        $model = AIModel::find($id);
        $user = auth()->user();
        if ($user->id !== $model->user_id && (Role::findOrFail($user->role_id)->slug !== 'admin' || Role::findOrFail($user->role_id)->slug !== 'master')) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $model->name = $name;
        $model->save();
        return response()->json(['message' => 'model name updated'], 200);
    }

    public function getModel(Request $request)
    {
        $query = AIModel::query();

        // Apply filters
        $filters = $request->input('filters', []);
        foreach ($filters as $key => $value) {
            if (in_array($key, (new AIModel)->getFillable())) {
                $query->where($key, $value);
            }
        }

        // Apply sorting
        $sortBy = $request->input('sortBy', 'id');
        $sortOrder = $request->input('sortOrder', 'asc');
        if (in_array($sortBy, ['id', 'name', 'status', 'created_at', 'updated_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Apply pagination
        $perPage = $request->input('perPage', 15);

        return $query->paginate($perPage);
    }

    // tested
    private function testScript($modelPath, $imagePath, $scriptPath)
    {
        $basePath = base_path();
        $scriptFilePath = $basePath . '/public/storage/scripts/' . basename($scriptPath);
        $modelFilePath = $basePath . '/public/storage/models/' . basename($modelPath);
        $imageFilePath = $basePath . '/public/storage/images/' . basename($imagePath);

        $process = new Process(['python3', $scriptFilePath, $modelFilePath, $imageFilePath]);
        $process->run();

        if (!$process->isSuccessful()) {
            $errorOutput = $process->getErrorOutput();
            $cleanedErrorOutput = preg_replace('/File ".*", /', '', $errorOutput);

            return response()->json([
                'error' => 'Process failed',
                'message' => $cleanedErrorOutput,
            ], 400);
        }

        $output = $process->getOutput();

        return response()->json(['output' => $output]);
    }


    // need testing
    public function predictModel(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'validation error', 'errors' => $validator->errors()], 422);
        }
        $model = AIModel::find($id);
        $fullImagePath = $request->file('image')->store('image');

        $process = new Process(['python3', $model->script_file, $model->model_file, $fullImagePath]);
        $process->run();

        // don't want to store extra images
        Storage::delete($fullImagePath);

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
        $output = $process->getOutput();

        if (preg_match('/\{.*\}/s', $output, $matches)) {
            $jsonOutput = $matches[0];
            $prediction = json_decode($jsonOutput, true);
        } else {
            $prediction = null;
        }

        return response()->json([
            'prediction' => $prediction['prediction'] ?? null
        ]);
    }
}
