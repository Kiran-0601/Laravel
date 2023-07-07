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
    var userId = $(this).data('id');
    console.log(userId);

    // Show the confirmation dialog
    if (confirm('Are you sure you want to delete this user?')) {
      var url = "{{route('users.delete','ID')}}";
      url = url.replace('ID', userId);
      $.ajax({
        url: url,
        type: "POST",
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
    } else {
      // User clicked "Cancel" in the confirmation dialog
    }
  });
});
</script>
@endsection