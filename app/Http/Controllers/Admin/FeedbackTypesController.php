<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeedbackType;
use Illuminate\Http\Request;

class FeedbackTypesController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view-feedback-type')->only('index');
        $this->middleware('permission:add-feedback-type')->only('create','store');
        $this->middleware('permission:edit-feedback-type')->only('edit','update');
        $this->middleware('permission:delete-feedback-type')->only('delete');
    }
    public function index(Request $request)
    {
        if ($request->ajax()) {

            $columns = array(
                0 => 'id',
                1 => 'feedback_type'
            );
            $query = FeedbackType::query();

            $totalData = $query->count();
            $totalFiltered = $totalData;
            $start = $request->input('start');
            $length = $request->input('length');
            $order = $columns[$request->input('order.0.column')];
            $dir = $request->input('order.0.dir');

            if (!empty($request->input('search.value'))) {
                $searchValue = $request->input('search.value');
                $query->where(function ($qry) use ($searchValue) {
                    $qry->where('feedback_type', 'LIKE', "%{$searchValue}%");
                });
                $totalFiltered = $query->count();
            }
            $filters = $query->offset($start)
                ->limit($length)
                ->orderBy($order, $dir)
                ->get();

            $data = array();
            if (!empty($filters)) {
                foreach ($filters as $value) {
                    $nestedData['id'] = $value->id;
                    $nestedData['feedback_type'] = $value->feedback_type;

                    // $actionButtons
                    $actionButtons = "";
                    if (auth()->user()->can('edit-feedback-type')) {
                        $actionButtons .= '<a href="' . route('feedback-types.edit', ['id' => $value->id]) . '"> Edit </a>';
                    }
                    if (auth()->user()->can('delete-feedback-type')) {
                        $actionButtons .= '<a href="" class="delete-btn" data-id="' . $value->id . '"> Delete</a>';
                    }
                    $nestedData['actions'] = $actionButtons;
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
        return view('admin.feedback-types.home');
    }
    public function create()
    {
        return view('admin.feedback-types.add');
    }
    public function store(Request $request)
    {
        $request->validate([
            'feedback_type' => 'required',
        ]);
        FeedbackType::create($request->all());
        return redirect()->back()->with('status', 'Feedback Type Added Successfully');
    }
    public function edit($id)
    {
        $data = FeedbackType::find($id);
        return view('admin.feedback-types.edit',compact('data'));
    }
    public function update(Request $request)
    {
        $request->validate([
            'feedback_type' => 'required',
        ]);
        FeedbackType::find($request->id)->update($request->all());
        //dd("save");
        return redirect()->route('feedback-types.edit', $request->id)->with('status', 'Feedback Type updated successfully');
    }
    public function delete(Request $request)
    {
        $id = $request->id;
        FeedbackType::destroy($id);
        return response()->json(['success' => true]);
    }
}
