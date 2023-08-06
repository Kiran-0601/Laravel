<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementCategory;
use App\Traits\ResponseTrait;
use App\Traits\UploadFileTrait;
use App\Validators\AnnouncementCategoryValidator;
use DB;
use Illuminate\Http\Request;

class AnnouncementCategoryController extends Controller
{
    
    use ResponseTrait, UploadFileTrait;

    private $announcementCategoryValidator;
    function __construct()
    {
        $this->announcementCategoryValidator = new AnnouncementCategoryValidator();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $data = AnnouncementCategory::orderBy('created_at', 'desc')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        try {
            DB::beginTransaction();
           
            $inputs = json_decode($request->data, true);
            $request->merge($inputs);
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->announcementCategoryValidator->validateStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $announcementCategory = AnnouncementCategory::create([
                'name' => $inputs['name'],
                'organization_id' => $organizationId
            ]);

            if (!empty($request->image)) {
                $image = $request->image;

                $path = config('constant.announcement_attachments');

                $file = $this->uploadFileOnLocal($image, $path);

                if (!empty($file['file_name'])) {
                    $imagePath = $file['file_name'];

                    AnnouncementCategory::where('id', $announcementCategory->id)->update(['image' => $imagePath]);
                }
                
            }


            DB::commit();

            return $this->sendSuccessResponse(__('messages.announcement_category_store'), 200, $announcementCategory);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add announcement";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
        
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $data = AnnouncementCategory::where('id',$id)->first();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            
            $inputs = json_decode($request->data, true);
            $request->merge($inputs);

            $category = AnnouncementCategory::where('id', $id)->first();

            $validation = $this->announcementCategoryValidator->validateUpdate($request, $category);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $data = [
                'name' => $inputs['name'] ?? null,
            ];


            if (!empty($request->image)) {
                $image = $request->image;

                $path = config('constant.announcement_attachments');

                $file = $this->uploadFileOnLocal($image, $path);

                if (!empty($file['file_name'])) {
                    $data['image'] = $file['file_name'];
                }                
            }

            AnnouncementCategory::where('id', $id)->update($data);

            $category = AnnouncementCategory::where('id', $id)->first();

            DB::commit();

            return $this->sendSuccessResponse(__('messages.announcement_category_update'), 200, $category);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while edit announcement";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            if(!empty($id)){
                $announcement = Announcement::where('announcement_category_id', $id)->first();
                if(empty($announcement)){
                    AnnouncementCategory::where('id', $id)->delete();
                }else{
                    return $this->sendSuccessResponse(__('messages.announcement_category_delete_warning'), 422);
                }
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.announcement_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete announcement";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
