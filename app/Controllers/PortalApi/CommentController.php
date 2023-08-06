<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\CommentType;
use App\Traits\ResponseTrait;
use App\Validators\CommentValidator;
use DB;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    use ResponseTrait;
    private $commentValidator;
    private $commentType;

    function __construct()
    {
        $this->commentValidator = new CommentValidator();

        $this->commentType = CommentType::select('id', 'type')->get()->pluck('id','type')->toArray();
    }
    
    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->commentValidator->validateStore($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $comment = Comment::create([
                'comment' => $inputs['comment'],
                'organization_id' => $organizationId,
                'comment_typeid' => $this->commentType[$inputs['comment_type_name']],
                'comment_typeid_id' => $inputs['comment_type'],
                'user_id' => $request->user()->id
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200, $comment);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add comment";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function getComments(Request $request)
    {
        $perPage = $request->perPage ?? 50;

        $query = Comment::where('comment_typeid', $this->commentType[$request->comment_type_name])
                       ->where('comment_typeid_id', $request->comment_type)
                       ->select('id','comment', 'created_at')
                       ->orderBy('id', 'desc');
        $count = $query->count();
        $comments = $query->simplePaginate($perPage);

        $response = ['data' => $comments, 'total_record' => $count];
        
        return $this->sendSuccessResponse(__('messages.success'), 200, $response);
    }

    public function update(Request $request, Comment $comment)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
            
            $validation = $this->commentValidator->validateUpdate($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $comment->update([
                'comment' => $inputs['comment']
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update comment";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }


     //Delete comment
     public function deleteComment(Comment $comment)
     {
         try {
             DB::beginTransaction();

             Comment::where('id', $comment->id)->delete();
 
             DB::commit();
 
             return $this->sendSuccessResponse(__('messages.success'), 200);
         } catch (\Throwable $ex) {
             DB::rollBack();
             $logMessage = "Something went wrong while delete comment";
             return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
         }
     }

}
