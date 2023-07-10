@extends('layouts.app')
@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h2 class="text-center">Change Password</h2></div>
                <div class="card-body">
                    @if(Session::has('status'))
                    <div id='alertMessage' class='alert alert-success message-container fade show position-fixed top-0 end-0 mt-5' role='alert'>
                        {{ Session::get('status') }}&nbsp;&nbsp;  <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>                        
                    </div>
                    @endif
                    <form method="POST" action="{{ route('save-change-password') }}">
                        @csrf
                        <div class="row mb-3">
                            <label for="oldpassword" class="col-md-4 col-form-label text-md-end">{{ __('Old Password') }}</label>
                            <div class="col-md-6">
                                <input type="hidden" id="id" name="id" value="{{ old('id', Auth::user()->id) }}"/>
                                <input id="oldpassword" type="password" class="form-control @error('oldpassword') is-invalid @enderror" name="oldpassword" value="{{ old('oldpassword') }}" autocomplete="oldpassword">
                                @error('oldpassword')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                                @enderror
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="newpassword" class="col-md-4 col-form-label text-md-end">{{ __('New Password') }}</label>
                            <div class="col-md-6">
                                <input id="newpassword" type="password" class="form-control @error('newpassword') is-invalid @enderror" name="newpassword" value="{{ old('newpassword') }}" autocomplete="newpassword">
                                @error('newpassword')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                                @enderror
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="cnfpassword" class="col-md-4 col-form-label text-md-end">Confirm Password</label>
                            <div class="col-md-6">
                                <input id="cnfpassword" type="password" class="form-control @error('cnfpassword') is-invalid @enderror" name="cnfpassword" value="{{ old('cnfpassword') }}" autocomplete="cnfpassword">
                                @error('cnfpassword')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                                @enderror
                            </div>
                        </div>
                        <div class="row mb-0">
                            <div class="col-md-8 offset-md-4">
                                <button type="submit" class="register">
                                    Update
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection