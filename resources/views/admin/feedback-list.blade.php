@extends('admin.layouts.app')
@section('content')
<div class="container">
  <div class="row justify-content-center">
    <div class="col-md-12">
      <table id="feedback-list" class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Feedback Type</th>
            <th>Feedback Description</th>
            <th>User Name</th>
            <th>Email</th>
          </tr>
        </thead>
      </table>
    </div>
  </div>
</div>
<script>
$(document).ready(function () {

  var table =  $('#feedback-list').DataTable({
    "processing": true,
    "serverSide": true,
    "deferRender": true,
    "ajax": {
      "url": "{{ route('admin.feedback') }}",
      "dataType": "json",
      "type": "GET"
    },
    "columns": [
      { 
        "data": "id",
        orderable: true
      },
      {
        "data": "type",
        orderable: true
      },
      {
        "data": "description",
        orderable: true
      },
      {
        data: 'name', name: 'user.name', orderable: true
      },
      {
        data: 'email', name: 'user.email', orderable: true
      }
    ],
  });
});
</script>
@endsection