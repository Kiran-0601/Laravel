@extends('admin.layouts.app')
@section('content')
<div class="container">
  <div class="row justify-content-center">
    <div class="col-md-12">
      @if(Session::has('success'))
      <div id='alertMessage' class='alert alert-success message-container fade show position-fixed top-0 end-0 mt-5' role='alert'>
        {{ Session::get('success') }}<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>                        
      </div>
      @endif
      <table id="users-table" class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Email</th>
            <th>Gender</th>
            <th>Action</th>
          </tr>
        </thead>
      </table>
    </div>
  </div>
</div>
<script>
$(document).ready(function () {

  var table =  $('#users-table').DataTable({
    "processing": true,
    "serverSide": true,
    "deferRender": true,
    "ajax": {
      "url": "{{ route('users') }}",
      "dataType": "json",
      "type": "GET"
    },
    "columns": [
      { 
        "data": "id",
        orderable: true
      },
      {
        "data": function (row) {
        return row.name + ' ' + row.lname;
        },
        orderable: true
      },
      {
        "data": "email",
        orderable: true
      },
      {
        "data": "gender",
        orderable: true
      },
      {
        "data": "actions",
        orderable: false
      }
    ],
  });
  $('#users-table').on('click', '.delete-btn', function(e) {
    e.preventDefault();
    userId = $(this).data('id');
    //console.log(userId);
    $('#deleteModal').modal('show');
  });
  $('#confirmDeleteBtn').on('click', function() {
    // Hide the modal
    $('#deleteModal').modal('hide');
    var url = "{{ route('users.delete','ID') }}";
    url = url.replace('ID', userId);

    $.ajax({
      url: url,
      type: 'POST',
      data: { id: userId },
      headers: {
        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
      },
      success: function(response) {
        // Handle success response
        console.log(response);
        table.ajax.reload(null, false);
        // Update the table after successful deletion
      },
      error: function(xhr, status, error) {
        console.error(error);
      }
    });
  });
});
</script>

 <!-- Bootstrap modal for Delete Confirmation -->
<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteModalLabel">Delete Confirmation</h5>
        <button type="button" class='btn-close' data-bs-dismiss="modal" aria-label="Close">
        </button>
      </div>
      <div class="modal-body">
        Are you sure you want to delete this user?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
      </div>
    </div>
  </div>
</div>
@endsection