@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h2 class="text-center">Add Addresses</h2></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('address.store') }}" enctype="multipart/form-data">
                        @csrf
                        @if(Session::has('status'))
                        <div id='alertMessage' class='alert alert-success message-container fade show position-fixed top-0 end-0 mt-5' role='alert'>
                            {{ Session::get('status') }}&nbsp;&nbsp;  <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>                        
                        </div>
                        @endif
                        <div class="row mb-3">
                            <label for="address" class="col-md-3 col-form-label text-md-end">Address Line1</label>
                            <div class="col-md-8">
                                <textarea id="addline1" class="form-control @error('addline1') is-invalid @enderror" name="addline1" rows="2" autocomplete="addline1">{{ old('addline1') }}</textarea>
                                @error('addline1')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                                @enderror
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="addline2" class="col-md-3 col-form-label text-md-end">Address Line2</label>
                            <div class="col-md-8">
                                <textarea id="addline2" class="form-control" name="addline2" rows="2" autocomplete="addline2">{{ old('addline2') }}</textarea>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="city" class="col-md-3 col-form-label text-md-end">City</label>
                            <div class="col-md-8">
                                <input id="city" type="text" class="form-control @error('city') is-invalid @enderror" name="city" autocomplete="city">
                                @error('city')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="pincode" class="col-md-3 col-form-label text-md-end">Pincode</label>
                            <div class="col-md-8">
                                <input id="pincode" type="text" class="form-control @error('pincode') is-invalid @enderror" name="pincode" autocomplete="pincode">
                                @error('pincode')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>
                        <button type="submit" class="register">Save</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
