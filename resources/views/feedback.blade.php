@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h2 class="text-center">Feedback Form</h2></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('feedback-submit') }}" enctype="multipart/form-data">
                        @csrf
                        @if(Session::has('status'))
                        <div id='alertMessage' class='alert alert-success message-container fade show position-fixed top-0 end-0 mt-5' role='alert'>
                            {{ Session::get('status') }}&nbsp;&nbsp;  <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>                        
                        </div>
                        @endif
                        <div class="row mb-3">
                            <label for="type" class="col-md-4 col-form-label text-md-end">Select Type</label>
                            <div class="col-md-6">
                                <select class="form-control @error('type') is-invalid @enderror" id="type" name="type">
                                    <option value="">Select Type</option>
                                    @foreach($type as $value)
                                        <option value="{{ $value->feedback_type }}">{{ $value->feedback_type }}</option>
                                    @endforeach
                                </select>
                                @error('type')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                                @enderror
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="description" class="col-md-4 col-form-label text-md-end">Description</label>
                            <div class="col-md-6">
                                <textarea id="description" class="form-control @error('description') is-invalid @enderror" name="description" rows="2" autocomplete="description">{{ old('description') }}</textarea>
                                @error('description')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                                @enderror
                            </div>
                        </div>
                        <div class="row mb-0 justify-content-center">
                            <button type="submit" class="register">
                                Submit
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
