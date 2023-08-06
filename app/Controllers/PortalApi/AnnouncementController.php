<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Models\AnnouncementCategory;
use App\Models\Employee;
use App\Models\Scopes\OrganizationScope;
use App\Validators\AnnouncementValidator;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;

class AnnouncementController extends Controller
{
    use ResponseTrait;

    private $announcementValidator;
    function __construct()
    {
        $this->announcementValidator = new AnnouncementValidator();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $date = Carbon::today()->subDays(30);
        $announcements = Announcement::leftJoin('announcement_categories', 'announcements.announcement_category_id', 'announcement_categories.id')->where('announcements.created_at','>=',$date)->orderBy('announcements.id', 'desc')->get(['title', 'description', 'name','image']);
        $path = config('constant.announcement_attachments');
        foreach($announcements as $announcement){
            if(!empty($announcement->image)){
                $announcement->image = getFullImagePath($path . '/' . $announcement->image);
            }
            
        }
        return $this->sendSuccessResponse(__('messages.success'), 200, $announcements);
    }

    //Get announcement list with pagination and filter
    public function getAnnouncementList(Request $request)
    {
        try {

            $keyword = $request->keyword ??  '';
            $perPage = $request->perPage ??  '';
            $organizationId = $this->getCurrentOrganizationId();

            $announcementData = Announcement::withoutGlobalScopes([OrganizationScope::class])->leftJoin('announcement_categories', 'announcements.announcement_category_id', 'announcement_categories.id')->orderBy('announcements.id', 'desc');

            $totalRecords = $announcementData->get()->count();

            $announcementData =  $announcementData->where(function ($q1) use ($keyword) {

                if (!empty($keyword)) {
                    $q1->where(function ($q2) use ($keyword) {
                        $q2->where('announcements.title', "like", '%' . $keyword . '%');
                        $q2->orWhere('announcements.description', "like", '%' . $keyword . '%');
                    });
                }
            });

            $announcementData = $announcementData->where('announcements.organization_id', $organizationId);

            $announcementData = $announcementData->select('announcements.title','announcements.id','announcement_categories.name as category', 'announcements.schedule_date');

            $announcementData = $announcementData->paginate($perPage);

            foreach ($announcementData as $value) {
                $date = convertUTCTimeToUserTime($value->created_at);
                $value->display_date = date('d-m-Y', strtotime($date));
            }

            $data['announcements'] = $announcementData;
            $data['count'] = $totalRecords;

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while list announcements";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
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
            $inputs = $request->all();
            $organizationId = $this->getCurrentOrganizationId();
            $validation = $this->announcementValidator->validateStore($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $announcement = Announcement::create([
                'title' => $inputs['title'],
                'description' => $inputs['description'],
                'announcement_category_id' => $inputs['category'] ?? null,
                'organization_id' => $organizationId,
                'schedule_date' => Carbon::parse($inputs['schedule_date'])->format("Y-m-d") ?? date('Y-m-d'),
            ]);

            if (!empty($request->attachments)) {
                $attachments = $request->attachments;

                $path = config('constant.announcement_attachments');

                foreach ($attachments as $attachment) {
                    $file = $this->uploadFileOnLocal($attachment, $path);

                    $fileName = $attachment->getClientOriginalName();

                    if (!empty($file['file_name'])) {
                        $attachmentData = [
                            'announcement_id' =>  $announcement->id,
                            'attachment_path' => $file['file_name'],
                            'file_name' => $fileName
                        ];

                        AnnouncementAttachment::create($attachmentData);
                    }
                }
            }


            DB::commit();

            return $this->sendSuccessResponse(__('messages.announcement_store'), 200, $announcement);
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
        $data = Announcement::where('id',$id)->first();
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
            $inputs = $request->all();

            $validation = $this->announcementValidator->validateUpdate($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $data = [
                'title' => $inputs['title'] ?? null,
                'description' => $inputs['description'] ?? null,
                'announcement_category_id' => $inputs['category'] ?? null,
                'schedule_date' => Carbon::parse($inputs['schedule_date'])->format("Y-m-d") ?? date('Y-m-d'),
            ];

            Announcement::where('id', $id)->update($data);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.announcement_update'), 200);
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

            Announcement::where('id', $id)->delete();

            DB::commit();

            return $this->sendSuccessResponse(__('messages.announcement_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete announcement";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //To store work aniversary in announcement table by cron
    public function getCompletionYearEmployee()
    {

        try {
            DB::beginTransaction();

            $threeYearCompletion = Employee::withoutGlobalScopes()->select('employees.display_name', 'employees.id','employees.organization_id')
                ->join('users', 'employees.id', '=', 'users.entity_id')
                ->where('is_active', 1)
                ->whereMonth('join_date', '=', getUtcDate('m'))
                ->whereDay('join_date', '=', getUtcDate('d'))
                ->whereRaw('YEAR(join_date) = YEAR(DATE_ADD(CURDATE(), INTERVAL -3 YEAR))')
                ->groupBy('employees.id')
                ->get();

            $fiveYearCompletion = Employee::withoutGlobalScopes()->select('employees.display_name', 'employees.id','employees.organization_id')
                ->join('users', 'employees.id', '=', 'users.entity_id')
                ->where('is_active', '=', '1')
                ->whereMonth('join_date', '=', getUtcDate('m'))
                ->whereDay('join_date', '=', getUtcDate('d'))
                ->whereRaw('YEAR(join_date) = YEAR(DATE_ADD(CURDATE(), INTERVAL -5 YEAR))')
                ->groupBy('employees.id')
                ->get();

            $category = AnnouncementCategory::where('name', AnnouncementCategory::WORKANIVERSARY)->first(['id']);
            foreach ($threeYearCompletion as $value) {
                Announcement::create([
                    'organization_id' => $value->organization_id,
                    'title' => 'Wish ' . $value->display_name . ' a happy 3rd work anniversary!',
                    'description' => $value->display_name . ' have set an exemplary standard for all of us with your work ethics and your dedication. We are glad to have you amongst us. Kudos to your amazing years of work!',
                    'announcement_category_id' => $category->id,
                    'extra_info' => json_encode(['employee_id' => $value->id]),
                    'schedule_date' => getUtcDate()
                ]);
            }


            foreach ($fiveYearCompletion as $value) {
                Announcement::create([
                    'organization_id' => $value->organization_id,
                    'title' => 'Wish ' . $value->display_name . ' a happy 5th work anniversary!',
                    'description' => $value->display_name . ' have set an exemplary standard for all of us with your work ethics and your dedication. We are glad to have you amongst us. Kudos to your amazing years of work!',
                    'announcement_category_id' => $category->id,
                    'extra_info' => json_encode(['employee_id' => $value->id]),
                    'schedule_date' => getUtcDate()
                ]);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.announcement_create'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while create announcement";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
