<?php

namespace App\Http\Controllers;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AddressController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax()) {

            $columns = array(
                0 => 'id',
                1 => 'addline1',
                2 => 'addline2',
                3 => 'city',
                4 => 'pincode'
            );
            $query = Address::query();

            $totalData = $query->count();
            $totalFiltered = $totalData;
            $start = $request->input('start');
            $length = $request->input('length');
            $order = $columns[$request->input('order.0.column')];
            $dir = $request->input('order.0.dir');

            if (!empty($request->input('search.value'))) {
                $searchValue = $request->input('search.value');
                $query->where(function ($qry) use ($searchValue) {
                    $qry->where('addline1', 'LIKE', "%{$searchValue}%")
                        ->orWhere('addline2', 'LIKE', "%{$searchValue}%")
                        ->orWhere('city', 'LIKE', "%{$searchValue}%")
                        ->orWhere('pincode', 'LIKE', "%{$searchValue}%");
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
                    $nestedData['addline1'] = $value->addline1;
                    $nestedData['addline2'] = $value->addline2 ?: "--";
                    $nestedData['city'] = $value->city;
                    $nestedData['pincode'] = $value->pincode;

                    // $actionButtons
                    $actionButtons = "";
                    $actionButtons .= '<a href="' . route('address.edit', ['id' => $value->id]) . '"> Edit |</a>';
                    $actionButtons .= '<a href="" class="delete-btn" data-id="' . $value->id . '">Delete</a>';
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
        return view('addresses.home');
    }
    public function create()
    {
        return view('addresses.add');
    }
    public function store(Request $request)
    {
        $data = $request->validate([
            'addline1' => 'required',
            'city' => 'required',
            'pincode' => 'required',
        ]);

        $data['user_id'] = Auth::id();
        $data['addline2'] = $request->addline2;
        Address::create($data);
        // dd("save");
        return redirect()->back()->with('status', 'Address Added successfully');

    }
    public function edit($id)
    {
        $data = Address::find($id);
        return view('addresses.edit',compact('data'));
    }
    public function delete(Request $request)
    {
        $id = $request->id;
        Address::destroy($id);
        return response()->json(['success' => true]);
    }
    public function update(Request $request)
    {
        $data = $request->validate([
            'addline1' => 'required',
            'city' => 'required',
            'pincode' => 'required',
        ]);
        $address = Address::find($request->id);
        $data['addline2'] = $request->addline2;
        $address->update($data);
        //dd("save");
        return redirect()->route('address.edit', $request->id)->with('status', 'Address updated successfully');
    }
}
