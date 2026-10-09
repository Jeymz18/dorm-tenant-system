<?php
require_once __DIR__ . '/../config/app.php';
require_role('tenant');

$db = get_db();
$tenant = current_tenant();

if (!$tenant || $tenant['approval_status'] !== 'Approved') {
    redirect('/tenant/dashboard.php');
}

mark_tenant_maintenance_updates_seen($db, (int) $tenant['tenant_id']);

$requests = $db->prepare('SELECT * FROM maintenance_requests WHERE tenant_id = ? ORDER BY COALESCE(updated_at, date_submitted) DESC, date_submitted DESC');
$requests->execute([(int) $tenant['tenant_id']]);
$requests = $requests->fetchAll();

$issueTypes = ['Plumbing', 'Electrical', 'HVAC / Air Conditioning', 'Heating', 'Furniture', 'Security / Locks', 'Pest Control', 'Other'];

$pageTitle = 'Maintenance Requests';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/page_header.php';
?>
<?php render_page_header('bi-wrench-adjustable', 'Maintenance Requests', 'Submit and track repair requests for your room.', null, 'Tenant Portal', '/tenant/dashboard.php'); ?>

<button type="button" class="btn btn-sm btn-action-primary mb-3" data-bs-toggle="collapse" data-bs-target="#newRequestForm">+ New Maintenance Request</button>

<div class="collapse mb-3" id="newRequestForm">
  <div class="panel">
    <div class="panel-header"><h2>Submit New Request</h2></div>
    <form method="post" action="<?= BASE_URL ?>/tenant/maintenance_process.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label">Issue Type *</label>
        <select class="form-select" name="issue_type" required>
          <option value="">Select issue type…</option>
          <?php foreach ($issueTypes as $type): ?><option><?= clean($type) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Description *</label>
        <textarea class="form-control" name="description" rows="3" placeholder="Please describe the issue in detail…" required></textarea>
      </div>
      <div class="mb-3">
        <label class="form-label">Upload Photo <span class="text-muted">(optional)</span></label>
        <div id="maintenanceDropzone" class="dropzone d-block" tabindex="0" aria-label="Upload maintenance photo">
          <span class="dz-icon d-flex justify-content-center align-items-center gap-2 text-maroon">
            <i class="bi bi-cloud-arrow-up-fill fs-3"></i>
            <i class="bi bi-camera-fill fs-4"></i>
          </span>
          <div>Take a photo or choose a file</div>
          <small class="dz-hint text-muted">Supports JPG, PNG up to 5MB</small>
          <div class="d-flex justify-content-center gap-2 mt-3 flex-wrap">
            <button type="button" class="btn btn-action-light rounded-pill photo-trigger" data-input="cameraInput">Take Photo</button>
            <button type="button" class="btn btn-action-light rounded-pill photo-trigger" data-input="fileInput">Upload Image File</button>
            <input type="file" id="cameraInput" name="camera_photo" accept="image/*" capture="environment" class="d-none">
            <input type="file" id="fileInput" name="maintenance_photo" accept="image/*" class="d-none">
          </div>
          <div id="maintenancePhotoPreview" class="mt-3 d-none">
            <div class="d-flex align-items-center gap-3 p-2 border rounded-3 bg-light text-start">
              <img id="maintenancePreviewImage" src="" alt="Preview" class="img-thumbnail" style="display:none; width:74px; height:74px; object-fit:cover;">
              <div class="flex-grow-1">
                <div class="small fw-semibold text-dark">Selected photo</div>
                <div id="maintenancePhotoName" class="small text-muted">No image selected</div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-action-primary rounded-pill flex-grow-1">Submit Request</button>
        <button type="button" class="btn btn-action-light rounded-pill" data-bs-toggle="collapse" data-bs-target="#newRequestForm">Cancel</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="maintenanceCameraModal" tabindex="-1" aria-labelledby="maintenanceCameraModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h5 class="modal-title" id="maintenanceCameraModalLabel">Take Maintenance Photo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <video id="maintenanceCameraPreview" class="w-100 rounded-3 bg-dark" autoplay playsinline></video>
        <canvas id="maintenanceCameraCanvas" class="d-none"></canvas>
        <p id="maintenanceCameraStatus" class="small text-muted mt-2 mb-0">Allow camera access to take a photo.</p>
      </div>
      <div class="modal-footer">
          <button type="button" class="btn btn-action-light" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-sm btn-action-primary" id="captureMaintenancePhoto"><i class="bi bi-camera-fill"></i> Capture Photo</button>
      </div>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><h2>Request History &amp; Status Tracker</h2></div>
  <?php if (!$requests): ?><p class="text-muted py-3">No maintenance requests yet.</p><?php endif; ?>
  <?php foreach ($requests as $r):
    $steps = ['Pending' => 1, 'Ongoing' => 2, 'Completed' => 3];
    $progress = $steps[$r['status']] ?? 1;
    $pct = $progress === 1 ? 15 : ($progress === 2 ? 60 : 100);
  ?>
    <div class="request-card">
      <div class="d-flex justify-content-between align-items-start gap-2">
        <strong><?= clean($r['issue_description']) ?></strong>
        <span class="badge badge-<?= status_badge_class($r['status']) ?>"><?= clean($r['status']) ?></span>
      </div>
      <div class="text-muted small mb-1"><?= clean($r['issue_title']) ?> · <?= clean(date('M j, Y', strtotime($r['date_submitted']))) ?></div>
      <?php if ($r['assigned_to']): ?><div class="small mb-2">Assigned to: <?= clean($r['assigned_to']) ?></div><?php endif; ?>
      <?php if (!empty($r['photo_file'])): ?>
        <div class="mb-2">
          <button type="button" class="btn btn-action-light rounded-pill maintenance-photo-trigger" data-image="<?= BASE_URL . '/' . ltrim($r['photo_file'], '/') ?>" data-title="<?= clean($r['issue_title']) ?>">
            <i class="bi bi-image"></i> View Photo
          </button>
        </div>
      <?php endif; ?>
      <div class="d-flex justify-content-between small text-muted"><span>Progress</span><span><?= $pct ?>%</span></div>
      <div class="request-progress-bar"><div class="request-progress-fill" style="width:<?= $pct ?>%"></div></div>
      <div class="timeline">
        <div class="timeline-step done">
          <div class="timeline-dot"><i class="bi bi-check-lg"></i></div>
          <div><div class="timeline-label">Request Received</div><div class="timeline-time"><?= clean(date('M j, Y g:i A', strtotime($r['date_submitted']))) ?></div></div>
        </div>
        <div class="timeline-step <?= $r['assigned_to'] ? 'done' : '' ?>">
          <div class="timeline-dot"><i class="bi bi-check-lg"></i></div>
          <div><div class="timeline-label">Technician Assigned</div><div class="timeline-time"><?= $r['assigned_to'] ? clean($r['assigned_to']) : '—' ?></div></div>
        </div>
        <div class="timeline-step <?= $progress >= 2 ? 'done' : '' ?>">
          <div class="timeline-dot"><i class="bi bi-check-lg"></i></div>
          <div><div class="timeline-label">In Progress</div><div class="timeline-time"><?= $progress >= 2 ? 'Underway' : '—' ?></div></div>
        </div>
        <div class="timeline-step <?= $progress >= 3 ? 'done' : '' ?>">
          <div class="timeline-dot"><i class="bi bi-check-lg"></i></div>
          <div><div class="timeline-label">Completed</div><div class="timeline-time"><?= $r['date_resolved'] ? clean(date('M j, Y g:i A', strtotime($r['date_resolved']))) : '—' ?></div></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="modal fade" id="maintenanceImageModal" tabindex="-1" aria-labelledby="maintenanceImageModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h5 class="modal-title" id="maintenanceImageModalLabel">Maintenance Photo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <img id="maintenanceImageModalImage" src="" alt="Maintenance request preview" class="img-fluid rounded-3 border">
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const cameraInput = document.getElementById('cameraInput');
  const fileInput = document.getElementById('fileInput');
  const previewBox = document.getElementById('maintenancePhotoPreview');
  const previewImage = document.getElementById('maintenancePreviewImage');
  const photoName = document.getElementById('maintenancePhotoName');
  const cameraModalElement = document.getElementById('maintenanceCameraModal');
  const cameraPreview = document.getElementById('maintenanceCameraPreview');
  const cameraCanvas = document.getElementById('maintenanceCameraCanvas');
  const cameraStatus = document.getElementById('maintenanceCameraStatus');
  const captureButton = document.getElementById('captureMaintenancePhoto');
  let cameraStream = null;

  function updatePreview(file) {
    if (!file || !previewBox || !previewImage || !photoName) {
      return;
    }

    const url = URL.createObjectURL(file);
    previewImage.src = url;
    previewImage.style.display = 'block';
    photoName.textContent = file.name;
    previewBox.classList.remove('d-none');
  }

  if (cameraInput) {
    cameraInput.addEventListener('change', function () {
      if (this.files && this.files[0]) {
        updatePreview(this.files[0]);
      }
    });
  }

  if (fileInput) {
    fileInput.addEventListener('change', function () {
      if (this.files && this.files[0]) {
        updatePreview(this.files[0]);
      }
    });
  }

  function isMobileDevice() {
    return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
  }

  function stopCameraStream() {
    if (cameraStream) {
      cameraStream.getTracks().forEach(function (track) {
        track.stop();
      });
      cameraStream = null;
    }
    if (cameraPreview) {
      cameraPreview.srcObject = null;
    }
  }

  async function openCameraModal() {
    if (!cameraModalElement || !cameraPreview || !captureButton || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      if (cameraInput) {
        cameraInput.value = '';
        cameraInput.click();
      }
      return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(cameraModalElement);
    cameraStatus.textContent = 'Requesting camera access…';
    captureButton.disabled = true;
    modal.show();

    try {
      cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
      cameraPreview.srcObject = cameraStream;
      cameraStatus.textContent = 'Position the maintenance issue in view, then capture the photo.';
      captureButton.disabled = false;
    } catch (error) {
      cameraStatus.textContent = 'Camera access was unavailable. You can choose an image file instead.';
      captureButton.disabled = true;
    }
  }

  if (captureButton) {
    captureButton.addEventListener('click', function () {
      if (!cameraPreview || !cameraCanvas || !cameraInput || !cameraPreview.videoWidth) {
        return;
      }

      cameraCanvas.width = cameraPreview.videoWidth;
      cameraCanvas.height = cameraPreview.videoHeight;
      cameraCanvas.getContext('2d').drawImage(cameraPreview, 0, 0, cameraCanvas.width, cameraCanvas.height);
      cameraCanvas.toBlob(function (blob) {
        if (!blob) return;
        const file = new File([blob], 'maintenance-camera-photo.jpg', { type: 'image/jpeg' });
        const transfer = new DataTransfer();
        transfer.items.add(file);
        cameraInput.files = transfer.files;
        updatePreview(file);
        bootstrap.Modal.getOrCreateInstance(cameraModalElement).hide();
      }, 'image/jpeg', 0.9);
    });
  }

  if (cameraModalElement) {
    cameraModalElement.addEventListener('hidden.bs.modal', function () {
      stopCameraStream();
      if (captureButton) captureButton.disabled = false;
    });
  }

  document.querySelectorAll('.photo-trigger').forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      const targetId = this.dataset.input;
      const target = document.getElementById(targetId);
      if (!target) return;
      if (targetId === 'cameraInput' && !isMobileDevice()) {
        openCameraModal();
        return;
      }
      target.value = '';
      target.click();
    });
  });

  const dropzone = document.getElementById('maintenanceDropzone');
  if (dropzone) {
    dropzone.addEventListener('click', function (event) {
      if (event.target.closest('button') || event.target.closest('input')) {
        return;
      }
      if (fileInput) {
        fileInput.value = '';
        fileInput.click();
      }
    });
    dropzone.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        if (fileInput) {
          fileInput.value = '';
          fileInput.click();
        }
      }
    });
  }

  document.querySelectorAll('.maintenance-photo-trigger').forEach(function (button) {
    button.addEventListener('click', function () {
      const modal = document.getElementById('maintenanceImageModal');
      const image = document.getElementById('maintenanceImageModalImage');
      if (!modal || !image) return;
      image.src = this.dataset.image || '';
      image.alt = this.dataset.title || 'Maintenance request photo';
      const bsModal = new bootstrap.Modal(modal);
      bsModal.show();
    });
  });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
