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
                            <div class="col-md-10">
                                <label class="form-label fw-bold">Email :</label>
                                {{$data->email}}                                
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label fw-bold">Address :</label>
                                {{ $data->address ? $data->address : 'Not given' }}
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="image" class="form-label fw-bold">Profile Picture</label>
                            <img id="cropped-image" src="{{ asset('storage/images/' . $data->image) }}" class="img-fluid"/>
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