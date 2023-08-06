<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Traits\ResponseTrait;
use App\Traits\UploadFileTrait;
use App\Validators\HolidayValidator;
use DB;
use Illuminate\Http\Request;

class HolidayController extends Controller
{

    use ResponseTrait, UploadFileTrait;

    public $holidayValidator;

    public function __construct(){

        $this->holidayValidator = new HolidayValidator();
    }

    public function index(Request $request)
    {
        $year = $request->year ?? null;

        $holidays = Holiday::whereYear('date', $year)->select('uuid', 'name', 'date', 'description', 'holiday_image')->get();

        return $this->sendSuccessResponse(__('messages.success'), 200, $holidays);
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();
            $inputs = $request->all();
            $organizationId = $this->getCurrentOrganizationId();
            $validation = $this->holidayValidator->validateStore($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $data = [
                'uuid' => getUuid(),
                'name' => $inputs['name'],
                'description' => $inputs['description'],
                'date' => $inputs['date'] ? date('Y-m-d', strtotime($inputs['date'])) : null,
                'organization_id' => $organizationId
            ];

            if (!empty($request->attachment)) {
                $attachment = $request->attachment;

                $path = config('constant.holiday_attachment');
                $file = $this->uploadFileOnLocal($attachment, $path);

                if (!empty($file['file_name'])) {
                    $data['holiday_image'] = $file['file_name'];
                }
            }

            $holiday = Holiday::create($data);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.holiday_store'), 200, $holiday);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add holiday";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function show($holiday)
    {
        $data = Holiday::where('uuid',$holiday)->first();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function update(Request $request, $holiday)
    {
        try {
            DB::beginTransaction();
            $inputs = $request->all();

            $validation = $this->holidayValidator->validateUpdate($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $holiday = Holiday::where('uuid', $holiday)->first();

            $data = [
                'name' => $inputs['name'],
                'description' => $inputs['description'],
                'date' => $inputs['date'] ? date('Y-m-d', strtotime($inputs['date'])) : null
            ];

            if (!empty($request->attachment)) {
                $attachment = $request->attachment;

                $path = config('constant.holiday_attachment');
                $file = $this->uploadFileOnLocal($attachment, $path);

                if (!empty($file['file_name'])) {
                    $data['holiday_image'] = $file['file_name'];
                }
            }

            $holiday->update($data);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.holiday_update'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update holiday";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function destroy($holiday)
    {
        try {
            DB::beginTransaction();

            Holiday::where('uuid', $holiday)->delete();

            DB::commit();

            return $this->sendSuccessResponse(__('messages.holiday_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete holiday";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

}
