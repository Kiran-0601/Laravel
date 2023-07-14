@extends('admin.layouts.app')
@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h2 class="text-center">User Data</h2></div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">First Name :</label>
                                {{$data->name}}                               
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Last Name :</label>
                                {{$data->lname}}                                
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Country :</label>
                                {{$data->country}}                               
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Mobile Number :</label>
                                {{$data->mobile}}                                
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Date Of Birth :</label>
                                {{$data->dob}}                               
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Gender :</label>
                                {{$data->gender}}                                
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Email :</label>
                                {{$data->email}}                                
                            </div>
                            <div class="col-md-6">
                                <label for="image" class="form-label fw-bold">Profile Picture :</label><br>
                                @if ($data->image)
                                <img id="cropped-image" src="{{ asset('storage/images/' . $data->image) }}" width="60" />
                                @else
                                <p>Not Uploaded</p>
                                @endif
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label fw-bold">Address :</label>
                                {{ $data->address ? $data->address : 'Not given' }}
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="addresses" class="form-label fw-bold">Addresses :</label>
                            @if ($data->addresses->count() > 0)
                                @foreach ($data->addresses as $address)
                                    <p>Address Line 1 :
                                    {{ $address->addline1 }}<br>
                                    Address Line 2 :
                                    {{ $address->addline2 ?? "Not Given" }}<br>
                                    City :
                                    {{ $address->city }}<br>
                                    Pincode :
                                    {{ $address->pincode }}</p><br>
                                @endforeach
                            @else
                                <p>Addresses Not found.</p>
                            @endif
                        </div>
                        <div class="row mb-0">
                            <div class="col-md-8">
                                <button onclick="window.location='{{ route('users') }}'" class="register">
                                   Back
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection