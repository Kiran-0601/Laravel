@extends('layouts.app')
@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h2 class="text-center">Edit Profile</h2></div>
                <div class="card-body">
                    @if(Session::has('success'))
                    <div id='alertMessage' class='alert alert-success message-container fade show position-fixed top-0 end-0 mt-5' role='alert'>
                        {{ Session::get('success') }}&nbsp;&nbsp;  <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>                        
                    </div>
                    @endif
                    <form method="POST" action="{{ route('update-profile') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="row mb-3">
                            <label for="name" class="col-md-4 col-form-label text-md-end">{{ __('First Name') }}</label>
                            <div class="col-md-6">
                                <input type="hidden" id="id" name="id" value="{{ old('id', Auth::user()->id) }}"/>
                                <input id="name" type="text" class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name', Auth::user()->name) }}" autocomplete="name">
                                @error('name')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                                @enderror
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="name" class="col-md-4 col-form-label text-md-end">{{ __('Last Name') }}</label>
                            <div class="col-md-6">
                                <input id="lname" type="text" class="form-control @error('lname') is-invalid @enderror" name="lname" value="{{ old('lname', Auth::user()->lname) }}" autocomplete="lname">
                                @error('lname')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                                @enderror
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="name" class="col-md-4 col-form-label text-md-end">Select Country</label>
                            <div class="col-md-6">
                                <select class="form-control @error('country') is-invalid @enderror" id="country" name="country">
                                    <option value="">Select Country</option>
                                    @foreach($countries as $value)
                                        <option value="{{ $value->code }}" {{ old('country', Auth::user()->country) == $value->code ? 'selected' : '' }}>{{ $value->name }}</option>
                                    @endforeach
                                </select>
                                @error('country')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                                @enderror
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="mobile" class="col-md-4 col-form-label text-md-end">Mobile Number</label>
                            <div class="col-md-6">
                                <input id="mobile" type="text" class="form-control @error('mobile') is-invalid @enderror" name="mobile" value="{{ old('mobile', Auth::user()->mobile) }}" autocomplete="mobile">
                                @error('mobile')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                                @enderror
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="dob" class="col-md-4 col-form-label text-md-end">Date Of Birth</label>
                            <div class="col-md-6">
                                <input id="dob" type="date" class="form-control @error('dob') is-invalid @enderror" name="dob" value="{{ old('dob',Auth::user()->dob) }}" autocomplete="dob">
                                @error('dob')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                                @enderror
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="email" class="col-md-4 col-form-label text-md-end">{{ __('Email Address') }}</label>
                            <div class="col-md-6">
                                <input id="email" type="email" class="form-control" name="email" value="{{ old('email',Auth::user()->email) }}" disabled autocomplete="email">                                
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="address" class="col-md-4 col-form-label text-md-end">Address</label>
                            <div class="col-md-6">
                                <textarea id="address" class="form-control @error('address') is-invalid @enderror" name="address" rows="2" autocomplete="address">{{ old('address', Auth::user()->address) }}</textarea>
                                @error('address')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                                @enderror
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label class="col-md-4 col-form-label text-md-end">Gender</label>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input @error('gender') is-invalid @enderror" type="radio" name="gender" id="male" value="male" {{ (old('gender') == 'male' || Auth::user()->gender == 'male') ? 'checked' : '' }}>
                                    <label for="male">Male</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input @error('gender') is-invalid @enderror" type="radio" name="gender" id="female" value="female" {{ (old('gender') == 'female' || Auth::user()->gender == 'female') ? 'checked' : '' }}>
                                    <label for="female">Female</label>
                                    @error('gender')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                    @enderror
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="image" class="col-md-4 col-form-label text-md-end">Profile Picture</label>
                            <div class="col-md-6">
                                <input type="file" name="image" class="image">
                                <img id="cropped-image" src="{{ asset('storage/images/' . Auth::user()->image) }}" class="img-fluid"/>
                                <input type="hidden" name="cropped-image" id="cropped-image-data">
                            </div>
                        </div>                     
                        <button type="submit" class="register">Update</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Image Crop Popup display -->
<div class="modal fade" id="modal" tabindex="-1" role="dialog" aria-labelledby="modalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalLabel">Crop image</h5>
                <button type="button" class="close" data-bs-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="img-container">
                    <div class="row">
                        <div class="col-md-8">  
                            <!--  default image where we will set the src via jquery-->
                            <img id="image">
                        </div>
                        <div class="col-md-4">
                            <div class="preview"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="crop">Crop</button>
            </div>
        </div>
    </div>
</div>

<script>
    var crop_model = $('#modal');
    var image = document.getElementById('image');
    var cropper,reader,file;
    $("body").on("change", ".image", function(e) {
        var files = e.target.files;
        var done = function(url) {
            image.src = url;
            $('#image').hide();
        };
        if (files && files.length > 0) {
            file = files[0];
            if (URL) {
                done(URL.createObjectURL(file));
                crop_model.modal('show');
            } else if (FileReader) {
                reader = new FileReader();
                reader.onload = function(e) {
                    done(reader.result);
                };
                reader.readAsDataURL(file);
            }
        }
    });
    crop_model.on('shown.bs.modal', function() {
        cropper = new Cropper(image, {
            aspectRatio: 1,
            viewMode: 3,
            preview: '.preview',
            dragMode: 'move',
            autoCropArea: 1,
            guides: false,
            center: false,
            highlight: false,
            cropBoxResizable: true, // Enable crop box resizing
            cropBoxMovable: true,
            toggleDragModeOnDblclick: false,
            minContainerWidth: 500, // Set the minimum container width to prevent blurriness
            minContainerHeight: 500,
        });
    }).on('hidden.bs.modal', function() {
        cropper.destroy();
        cropper = null;
    });
    $("#crop").click(function() {
        canvas = cropper.getCroppedCanvas({
        });
        var croppedImageDataURL = canvas.toDataURL('image/jpeg');
        $('#cropped-image').attr('src', croppedImageDataURL);
        // retrive the url in  hidden field
        document.getElementById('cropped-image-data').value = croppedImageDataURL;
        // Close the crop modal window
        crop_model.modal('hide');
    });
</script>
@endsection
