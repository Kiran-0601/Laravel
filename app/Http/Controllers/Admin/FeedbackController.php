<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Feedback;

class FeedbackController extends Controller
{
    public function feedbackList(Request $request)
    {
        if ($request->ajax()) {
            $columns = array(
                0 => 'id',
                1 => 'type',
                2 => 'description',
                3 => 'name',
                4 => 'email'
            );
            $query = Feedback::join('users', 'feedback.user_id', '=', 'users.id')
            ->select('feedback.*', 'users.name', 'users.email');

            if (!empty($request->input('search.value'))) {
                $searchValue = $request->input('search.value');
                $query->where(function ($qry) use ($searchValue) {
                    $qry->where('type', 'LIKE', "%{$searchValue}%")
                        ->orWhere('description', 'LIKE', "%{$searchValue}%")
                        ->orWhereHas('user', function ($q) use ($searchValue) {
                            $q->where('name', 'LIKE', "%{$searchValue}%")
                                ->orWhere('email', 'LIKE', "%{$searchValue}%");
                        });
                });
            }
            $totalData = $query->count();
            $totalFiltered = $totalData;
            $start = $request->input('start');
            $length = $request->input('length');
            $order = $columns[$request->input('order.0.column')];
            $dir = $request->input('order.0.dir');
            $filters = $query->offset($start)
                ->limit($length)
                ->orderBy($order, $dir)
                ->get();

            $data = array();
            if (!empty($filters)) {
                foreach ($filters as $value) {
                    $nestedData['id'] = $value->id;
                    $nestedData['type'] = $value->type;
                    $nestedData['description'] = $value->description;
                    $nestedData['name'] = $value->user->name ?? '';
                    $nestedData['email'] = $value->user->email ?? '';
            
                    $data[] = $nestedData;
                }
            }
            $jsonData = [
                'draw' => $request->input('draw'),
                'recordsTotal' => $totalData,
                'recordsFiltered' => $totalFiltered,
                'data' => $data,
            ];
            return response()->json($jsonData);               
        }
        return view('admin.feedback-list');
    }
}
