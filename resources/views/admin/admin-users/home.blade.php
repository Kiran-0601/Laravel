@extends('admin.layouts.app')
@section('content')
<div class="container">
  <div class="row justify-content-center">
    <div class="col-md-12">
      @can('add-admin-user')
      <button  onclick="window.location.href='{{ route('admin-user.create') }}'"  class="register">Add Admin User</button>
      @endcan
      <table id="admin-users-table" class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Action</th>
          </tr>
        </thead>
      </table>
    </div>
  </div>
</div>
<script>
$(document).ready(function () {

  var table =  $('#admin-users-table').DataTable({
    "processing": true,
    "serverSide": true,
    "deferRender": true,
    "ajax": {
      "url": "{{ route('admin-user') }}",
      "dataType": "json",
      "type": "GET"
    },
    "columns": [
      {
        "data": "id",
        orderable: true
      },
      {
        "data": "name",
        orderable: true
      },
      {
        "data": "email",
        orderable: true
      },
      {
        "data": "role",
        orderable: true
      },
      {
        "data": "actions",
        orderable: false
      }
    ],
  });
  $('#admin-users-table').on('click', '.delete-btn', function(e) {
    e.preventDefault();
    userId = $(this).data('id');
    // alert(userId);
    $('#deleteModal').modal('show');
  });
  $('#confirmDeleteBtn').on('click', function() {
    // Hide the modal
    $('#deleteModal').modal('hide');
    var url = "{{ route('admin-user.delete') }}";

    $.ajax({
      url: url,
      type: 'GET',
      data: { id: userId },
      headers: {
        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
      },
      success: function(response) {
        // Handle success response
        console.log(response);
        table.ajax.reload(null, false);       // Reload the table after successful deletion
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
        Are you sure you want to delete this Admin User?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
      </div>
    </div>
  </div>
</div>
@endsection
