@extends('settings.template')

@section('section')

  <div class="title">
    <h3 class="font-weight-bold">Notification Settings</h3>
  </div>
  <hr>
  <div id="browser-notifications" class="card shadow-none border">
    <div class="card-body">
      <h4 class="h5 font-weight-bold">Browser Notifications</h4>
      <p class="text-muted">Manage notifications for this browser and device.</p>
      <p class="mb-1">Status: <span data-push-status role="status" aria-live="polite">Checking browser notifications…</span></p>
      <p class="mb-1">Browser permission: <span data-push-permission>Checking…</span></p>
      <p class="mb-3">Subscription on this device: <span data-push-subscription>Checking…</span></p>
      <div data-push-message class="alert d-none" role="alert"></div>
      <button type="button" data-push-enable class="btn btn-primary d-none">Enable Browser Notifications</button>
      <button type="button" data-push-disable class="btn btn-outline-secondary d-none">Disable Browser Notifications</button>
    </div>
  </div>

@endsection
