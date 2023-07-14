@extends('admin.layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h2 class="text-center">Add Feedback Type</h2></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('feedback-types.store') }}" enctype="multipart/form-data">
                        @csrf
                        @if(Session::has('status'))
                        <div id='alertMessage' class='alert alert-success message-container fade show position-fixed top-0 end-0 mt-5' role='alert'>
                            {{ Session::get('status') }}&nbsp;&nbsp;  <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>                        
                        </div>
                        @endif
                        <div class="row mb-3">
                            <label for="feedback_type" class="col-md-3 col-form-label text-md-end">Feedback Type</label>
                            <div class="col-md-8">
                                <input id="feedback_type" type="text" class="form-control @error('feedback_type') is-invalid @enderror" name="feedback_type" autocomplete="feedback_type">
                                @error('feedback_type')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>
                        </div>
                        <button type="submit" class="register">Add</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
