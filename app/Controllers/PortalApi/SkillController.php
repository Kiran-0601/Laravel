<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Validators\SkillValidator;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use App\Models\Skill;
use Storage;
use Str;
use DB;

class SkillController extends Controller
{
    use ResponseTrait;
    public $skillValidator;

    public function __construct(){
        $this->skillValidator = new SkillValidator();
    }
    public function skillList()
    {
        $data = Skill::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }
    public function list(Request $request)
    {
        $perPage = $request->perPage ?? '';
        $keyword = $request->keyword ?? '';
        $query = Skill::select('uuid', 'name');
        $query->when($keyword, function($que) use($keyword){
            $que->where('name', 'like', '%'.$keyword.'%');
        });

        $skillData = $query->simplePaginate($perPage);

        $totalCount = Skill::count();

        $response = [
            'data' => $skillData,
            'total_count' => $totalCount,

        ];

        return $this->sendSuccessResponse(__('messages.success'), 200, $response);
    }
    public function store(Request $request)
    {
        try {
            DB::beginTransaction();
            $inputs = $request->all();
            $organizationId = $this->getCurrentOrganizationId();
            $validation = $this->skillValidator->validateStore($request,$organizationId);
            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }
            $data = [
                'uuid' => getUuid(),
                'organization_id' => $organizationId,
                'name' => $inputs['name'],
                'slug' => Str::slug($inputs['name'])
            ];
            $skill = Skill::create($data);
            DB::commit();
            return $this->sendSuccessResponse(__('messages.skill_store'), 200, $skill);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add skill";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function skillDetails($skill)
    {
        $data = Skill::select('name')->where('uuid', $skill)->first();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }
    public function update(Request $request, $skill)
    {
        try {
            DB::beginTransaction();
            $inputs = $request->all();
            $skill = Skill::where('uuid', $skill)->first();
            $validation = $this->skillValidator->validateUpdate($request,$skill);
            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }
            $skill->update([
                'name' => $inputs['name'],
                'slug' => Str::slug($inputs['name'])
            ]);
            DB::commit();
            return $this->sendSuccessResponse(__('messages.skill_update'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update skill";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
    public function delete($skill)
    {
        try {
            DB::beginTransaction();
            Skill::where('uuid', $skill)->delete();
            DB::commit();
            return $this->sendSuccessResponse(__('messages.skill_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete skill";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
