<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view-role')->only('index');
        $this->middleware('permission:add-role')->only('create','store');
        $this->middleware('permission:edit-role')->only('edit','update');
        $this->middleware('permission:delete-role')->only('delete');
    }
    public function index(Request $request)
    {
        if ($request->ajax()) {

            $columns = array(
                0 => 'id',
                1 => 'name'
            );
            $query = Role::query();
            $totalData = $query->count();
            $totalFiltered = $totalData;
            $start = $request->input('start');
            $length = $request->input('length');
            $order = $columns[$request->input('order.0.column')];
            $dir = $request->input('order.0.dir');

            if (!empty($request->input('search.value'))) {
                $searchValue = $request->input('search.value');
                $query->where(function ($qry) use ($searchValue) {
                    $qry->where('name', 'LIKE', "%{$searchValue}%");
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
                    $nestedData['name'] = $value->name;

                    // $actionButtons
                    $actionButtons = "";
                    if (auth()->user()->can('edit-role')) {
                        $actionButtons .= '<a href="' . route('roles.edit', ['id' => $value->id]) . '"> Edit</a>';
                    }
                    if (auth()->user()->can('delete-role')) {
                        if ($value->name !== 'Admin') {
                            $actionButtons .= '<a href="" class="delete-btn" data-id="' . $value->id . '"> | Delete</a>';
                        }
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
        return view('admin.roles.home');
    }
    public function create()
    {
        $permissions = Permission::all();
        return view('admin.roles.add', compact('permissions'));
    }
    public function store(Request $request)
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $validatedData = $request->validate([
            'name' => 'required|unique:roles|max:255',
        ]);
        // Create the role
        $role = Role::create([
            'name' => $validatedData['name'],
        ]);
        $role->permissions()->sync($request->permissions);
        //print("save");
        return redirect()->back()->with('status', 'Role Added Successfully');
    }
    public function edit($id)
    {
        $role = Role::find($id);
        $permissions = Permission::all();
        return view('admin.roles.edit', compact('role','permissions'));
    }
    public function update(Request $request)
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $role = Role::find($request->id);
        $role->permissions()->sync($request->permissions);
        return redirect()->route('roles.edit', $request->id)->with('status', 'Role Updated Successfully');
    }
    public function delete(Request $request)
    {
        Role::destroy($request->id);
        return response()->json(['success' => true]);
    }
}