@extends('admin.layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card">
                <div class="card-header"><h2 class="text-center">Edit Role</h2></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('roles.update') }}">
                        @csrf
                        @if(Session::has('status'))
                        <div id='alertMessage' class='alert alert-success message-container fade show position-fixed top-0 end-0 mt-5' role='alert'>
                            {{ Session::get('status') }}&nbsp;&nbsp;  <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>                        
                        </div>
                        @endif
                        <input type="hidden" value="{{ $role->id }}" name="id">
                        <div class="row mb-2">
                            <label for="name" class="col-md-2 col-form-label">Role Name :</label>
                            <div class="col-md-10">
                                <input id="name" type="text" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $role->name) }}" name="name" disabled>
                            </div>
                        </div>
                        <label class="col-md-2 col-form-label" for="permissions">Permissions :</label>
                        <div class="row mb-2">
                            @foreach ($permissions as $permission)
                            <div class="col-md-4">
                                <div class="form-switch" style="padding-bottom: 20px;">
                                    <input class="form-check-input toggle-switch" name="permissions[]" type="checkbox" role="switch" data-id="{{ $permission->id }}" value="{{ $permission->id }}" 
                                    data-toggle="toggle" data-onstyle="success" data-offstyle="danger" data-on="Enabled" data-off="Disabled"
                                    {{ $role->permissions->contains($permission) ? 'checked' : '' }}>
                                    <label>{{ $permission->name }}</label>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        <button type="submit" class="register" style="width: 12%;">Update</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
