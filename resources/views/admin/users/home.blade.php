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
    "ajax": {
      "url": "{{ route('users') }}",
      "type": "GET"
    },
    "columns": [
      { "data": "id" },
      { 
        "data": function (row) {
        return row.name + ' ' + row.lname;
        },
      },
      { "data": "email" },
      { "data": "gender" },      
      {
        "data": null,
        "render": function(data, type, row) {
          return '<a href="{{ route("users.view", ["id" => ":id"]) }}"><i class="fas fa fa-eye"></i>View</a> &nbsp;&nbsp; <a href="{{ route("users.edit", ["id" => ":id"]) }}"><i class="fa fa-edit"></i>Edit</a>&nbsp;&nbsp; <a href="{{ route("users.delete", ["id" => ":id"]) }}" class="delete-link"><i class="fa fa-trash o"></i>Delete</a>'.replace(/:id/g, data.id);
        }
      }
    ],
  });
});    
</script>
@endsection