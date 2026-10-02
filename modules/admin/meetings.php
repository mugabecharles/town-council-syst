<?php
/**
 * TCMS Council Meetings & Resolutions Module
 * Record meetings, agenda items, attendees, minutes and track resolutions.
 */
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();
$fy   = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;

// ══════════════════════════════════════════════════════════════════
// POST HANDLERS
// ══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Create Meeting ────────────────────────────────────────────
    if ($action === 'create_meeting') {
        $cnt  = (int)$db->query("SELECT COUNT(*)+1 FROM council_meetings")->fetchColumn();
        $type = substr(strtoupper(str_replace(' ','_',$_POST['meeting_type'])),0,3);
        $mnum = 'MTG-'.$type.'-'.date('Y').'-'.str_pad($cnt,4,'0',STR_PAD_LEFT);

        $db->prepare("INSERT INTO council_meetings
            (meeting_number,meeting_type,title,venue,meeting_date,start_time,
             chairperson,secretary,financial_year,agenda,status,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,'scheduled',?)")
           ->execute([
               $mnum, $_POST['meeting_type'], trim($_POST['title']),
               trim($_POST['venue']??''), $_POST['meeting_date'],
               $_POST['start_time']??null, trim($_POST['chairperson']??''),
               trim($_POST['secretary']??''), $fy,
               trim($_POST['agenda']??''), $user['id']
           ]);
        $mid = (int)$db->lastInsertId();
        logAudit('CREATE','meetings','meeting',$mid,$mnum);
        setFlash('success',"Meeting created: <strong>$mnum</strong>");
        header('Location: meetings.php?view='.$mid); exit;
    }

    // ── Update Meeting Status ─────────────────────────────────────
    if ($action === 'update_meeting') {
        $mid = (int)$_POST['meeting_id'];
        $db->prepare("UPDATE council_meetings SET
            title=?,venue=?,meeting_date=?,start_time=?,end_time=?,
            chairperson=?,secretary=?,quorum_met=?,status=?,
            summary=?,next_meeting_date=?,attendees_count=? WHERE id=?")
           ->execute([
               trim($_POST['title']), trim($_POST['venue']??''),
               $_POST['meeting_date'], $_POST['start_time']??null,
               $_POST['end_time']??null,
               trim($_POST['chairperson']??''), trim($_POST['secretary']??''),
               isset($_POST['quorum_met'])?1:0,
               $_POST['status'], trim($_POST['summary']??''),
               !empty($_POST['next_meeting_date'])?$_POST['next_meeting_date']:null,
               (int)($_POST['attendees_count']??0), $mid
           ]);
        logAudit('UPDATE','meetings','meeting',$mid,'');
        setFlash('success','Meeting updated.');
        header('Location: meetings.php?view='.$mid); exit;
    }

    // ── Add Agenda Item ───────────────────────────────────────────
    if ($action === 'add_agenda') {
        $mid  = (int)$_POST['meeting_id'];
        $inum = (int)$db->prepare("SELECT COALESCE(MAX(item_number),0)+1 FROM meeting_agenda WHERE meeting_id=?")->execute([$mid]) ?
                $db->query("SELECT COALESCE(MAX(item_number),0)+1 FROM meeting_agenda WHERE meeting_id=$mid")->fetchColumn() : 1;
        $db->prepare("INSERT INTO meeting_agenda (meeting_id,item_number,title,description,presenter,duration_minutes)
            VALUES (?,?,?,?,?,?)")
           ->execute([$mid,$inum,trim($_POST['title']),trim($_POST['description']??''),
                      trim($_POST['presenter']??''),(int)($_POST['duration_minutes']??15)]);
        setFlash('success','Agenda item added.');
        header('Location: meetings.php?view='.$mid.'#agenda'); exit;
    }

    // ── Update Agenda Item Outcome ────────────────────────────────
    if ($action === 'update_agenda') {
        $aid = (int)$_POST['agenda_id'];
        $mid = (int)$_POST['meeting_id'];
        $db->prepare("UPDATE meeting_agenda SET status=?,outcome=? WHERE id=?")
           ->execute([$_POST['status'],trim($_POST['outcome']??''),$aid]);
        setFlash('success','Agenda item updated.');
        header('Location: meetings.php?view='.$mid.'#agenda'); exit;
    }

    // ── Add Attendee ──────────────────────────────────────────────
    if ($action === 'add_attendee') {
        $mid = (int)$_POST['meeting_id'];
        $db->prepare("INSERT INTO meeting_attendees (meeting_id,attendee_type,name,designation,department,attended,apology)
            VALUES (?,?,?,?,?,?,?)")
           ->execute([$mid,$_POST['attendee_type']??'staff',trim($_POST['name']),
                      trim($_POST['designation']??''),trim($_POST['department']??''),
                      isset($_POST['attended'])?1:0,isset($_POST['apology'])?1:0]);
        // Update attendee count
        $db->prepare("UPDATE council_meetings SET attendees_count=(SELECT COUNT(*) FROM meeting_attendees WHERE meeting_id=? AND attended=1) WHERE id=?")->execute([$mid,$mid]);
        setFlash('success','Attendee added.');
        header('Location: meetings.php?view='.$mid.'#attendees'); exit;
    }

    // ── Add Resolution ────────────────────────────────────────────
    if ($action === 'add_resolution') {
        $mid  = (int)$_POST['meeting_id'];
        $cnt  = (int)$db->query("SELECT COUNT(*)+1 FROM council_resolutions")->fetchColumn();
        $rnum = 'RES-'.date('Y').'-'.str_pad($cnt,4,'0',STR_PAD_LEFT);
        $db->prepare("INSERT INTO council_resolutions
            (resolution_number,meeting_id,agenda_id,financial_year,title,resolution_text,
             responsible_dept,responsible_officer,target_date,priority)
            VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $rnum, $mid,
               !empty($_POST['agenda_id'])?(int)$_POST['agenda_id']:null,
               $fy, trim($_POST['title']), trim($_POST['resolution_text']),
               !empty($_POST['responsible_dept'])?(int)$_POST['responsible_dept']:null,
               trim($_POST['responsible_officer']??''),
               !empty($_POST['target_date'])?$_POST['target_date']:null,
               $_POST['priority']??'normal'
           ]);
        $rid = (int)$db->lastInsertId();
        logAudit('CREATE','meetings','resolution',$rid,$rnum);
        setFlash('success',"Resolution <strong>$rnum</strong> recorded.");
        header('Location: meetings.php?view='.$mid.'#resolutions'); exit;
    }

    // ── Update Resolution Status ──────────────────────────────────
    if ($action === 'update_resolution') {
        $rid = (int)$_POST['resolution_id'];
        $mid = (int)$_POST['meeting_id'];
        $db->prepare("UPDATE council_resolutions SET status=?,implementation_notes=?,
            implemented_date=? WHERE id=?")
           ->execute([$_POST['status'],trim($_POST['implementation_notes']??''),
                      !empty($_POST['implemented_date'])?$_POST['implemented_date']:null,$rid]);
        logAudit('UPDATE','meetings','resolution',$rid,'');
        setFlash('success','Resolution status updated.');
        header('Location: meetings.php?view='.$mid.'#resolutions'); exit;
    }

    // ── Upload Minutes ────────────────────────────────────────────
    if ($action === 'upload_minutes') {
        $mid = (int)$_POST['meeting_id'];
        if (!empty($_FILES['minutes_file']) && $_FILES['minutes_file']['error']===UPLOAD_ERR_OK) {
            $result = handleFileUpload($_FILES['minutes_file'],'documents/'.date('Y/m'));
            if ($result['success']) {
                $docNum = generateDocNumber();
                $db->prepare("INSERT INTO documents (doc_number,title,doc_type,related_module,related_id,file_name,file_path,file_size,file_type,uploaded_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$docNum,'Council Minutes — '.date('F Y'),'minutes','meeting',$mid,
                              $result['file_name'],$result['file_path'],$result['file_size'],$result['file_type'],$user['id']]);
                $docId = (int)$db->lastInsertId();
                $db->prepare("UPDATE council_meetings SET document_id=? WHERE id=?")->execute([$docId,$mid]);
                setFlash('success','Minutes uploaded successfully.');
            } else {
                setFlash('danger','Upload failed: '.$result['error']);
            }
        }
        header('Location: meetings.php?view='.$mid); exit;
    }
}

// ══════════════════════════════════════════════════════════════════
// VIEW — Single Meeting Detail
// ══════════════════════════════════════════════════════════════════
$viewId = (int)($_GET['view'] ?? 0);
if ($viewId) {
    $meeting = $db->prepare("SELECT m.*, u.full_name AS created_by_name, doc.file_path AS minutes_path
        FROM council_meetings m JOIN users u ON m.created_by=u.id
        LEFT JOIN documents doc ON m.document_id=doc.id
        WHERE m.id=?");
    $meeting->execute([$viewId]); $meeting = $meeting->fetch();
    if (!$meeting) { setFlash('danger','Meeting not found.'); header('Location: meetings.php'); exit; }

    $agenda     = $db->prepare("SELECT * FROM meeting_agenda WHERE meeting_id=? ORDER BY item_number");
    $agenda->execute([$viewId]); $agenda = $agenda->fetchAll();

    $attendees  = $db->prepare("SELECT * FROM meeting_attendees WHERE meeting_id=? ORDER BY attended DESC, name");
    $attendees->execute([$viewId]); $attendees = $attendees->fetchAll();

    $resolutions= $db->prepare("SELECT r.*,d.name AS dept_name FROM council_resolutions r
        LEFT JOIN departments d ON r.responsible_dept=d.id
        WHERE r.meeting_id=? ORDER BY r.created_at");
    $resolutions->execute([$viewId]); $resolutions = $resolutions->fetchAll();

    $depts      = $db->query("SELECT * FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();
    $statusOpts = ['pending','in_progress','implemented','deferred','cancelled'];
    $agendaForRes = $agenda; // for resolution form

    renderHead('Meeting: '.$meeting['meeting_number']);
    echo '<body><div class="app-wrapper">';
    renderSidebar($user);
    echo '<div class="main-content">';
    renderTopbar('Council Meeting',''.htmlspecialchars($meeting['meeting_number']));
    renderPageStart(htmlspecialchars($meeting['title']),'', [
        ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
        ['url'=>APP_URL.'/modules/admin/meetings.php','label'=>'Meetings'],
        ['url'=>'#','label'=>$meeting['meeting_number']],
    ]);
    renderPageActions('
      <button class="btn btn-outline-secondary no-print" data-print>🖨 Print</button>
      <a href="meetings.php" class="btn btn-outline-secondary">← Back</a>
    ');
    renderFlashMessages();
    ?>

<!-- Meeting Header Card -->
<div class="card" style="margin-bottom:1.2rem;border-top:4px solid var(--primary);">
  <div class="card-body">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
      <div>
        <div style="display:flex;gap:.6rem;align-items:center;margin-bottom:.5rem;">
          <span class="fw-700 text-primary" style="font-size:1.1rem;"><?= htmlspecialchars($meeting['meeting_number']) ?></span>
          <?= getStatusBadge($meeting['status']) ?>
          <span class="badge badge-info"><?= ucwords(str_replace('_',' ',$meeting['meeting_type'])) ?></span>
          <?php if (!$meeting['quorum_met']): ?><span class="badge badge-warning">No Quorum</span><?php endif; ?>
        </div>
        <h2 style="font-size:1.15rem;font-weight:800;color:var(--primary);margin-bottom:.5rem;"><?= htmlspecialchars($meeting['title']) ?></h2>
        <div class="grid-4" style="gap:.5rem;font-size:.83rem;">
          <div><span class="text-muted">Date:</span> <strong><?= formatDate($meeting['meeting_date']) ?></strong></div>
          <div><span class="text-muted">Time:</span> <strong><?= $meeting['start_time'] ? substr($meeting['start_time'],0,5) : '—' ?><?= $meeting['end_time'] ? ' – '.substr($meeting['end_time'],0,5) : '' ?></strong></div>
          <div><span class="text-muted">Venue:</span> <strong><?= htmlspecialchars($meeting['venue']??'—') ?></strong></div>
          <div><span class="text-muted">Attendees:</span> <strong><?= $meeting['attendees_count'] ?></strong></div>
          <div><span class="text-muted">Chairperson:</span> <strong><?= htmlspecialchars($meeting['chairperson']??'—') ?></strong></div>
          <div><span class="text-muted">Secretary:</span> <strong><?= htmlspecialchars($meeting['secretary']??'—') ?></strong></div>
          <div><span class="text-muted">FY:</span> <strong><?= $meeting['financial_year'] ?></strong></div>
          <?php if ($meeting['next_meeting_date']): ?>
          <div><span class="text-muted">Next Meeting:</span> <strong><?= formatDate($meeting['next_meeting_date']) ?></strong></div>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:.5rem;">
        <button class="btn btn-sm btn-outline-primary" data-modal="editMeetingModal">Edit Details</button>
        <?php if ($meeting['minutes_path']): ?>
        <a href="<?= APP_URL ?>/uploads/<?= htmlspecialchars($meeting['minutes_path']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">📄 View Minutes</a>
        <?php else: ?>
        <button class="btn btn-sm btn-outline-secondary" data-modal="uploadMinutesModal">📄 Upload Minutes</button>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($meeting['summary']): ?>
    <div style="margin-top:1rem;padding:1rem;background:#f8f9fa;border-radius:6px;font-size:.86rem;">
      <strong>Summary:</strong> <?= htmlspecialchars($meeting['summary']) ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="grid-2" style="align-items:start;">

<!-- Left: Agenda + Resolutions -->
<div>
  <!-- Agenda Items -->
  <div class="card" id="agenda" style="margin-bottom:1rem;">
    <div class="card-header">
      <h5><span class="ch-icon">◆</span> Agenda (<?= count($agenda) ?> items)</h5>
      <button class="btn btn-sm btn-primary" data-modal="addAgendaModal">+ Add Item</button>
    </div>
    <div class="card-body p-0">
      <?php if ($agenda): ?>
      <?php foreach ($agenda as $ai): ?>
      <div style="padding:1rem 1.2rem;border-bottom:1px solid var(--border);">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.8rem;">
          <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.3rem;">
              <span style="width:24px;height:24px;background:var(--primary);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;flex-shrink:0;"><?= $ai['item_number'] ?></span>
              <span class="fw-700"><?= htmlspecialchars($ai['title']) ?></span>
              <?= getStatusBadge($ai['status']) ?>
            </div>
            <?php if ($ai['description']): ?><p class="fs-sm text-muted" style="margin-left:30px;margin-bottom:.3rem;"><?= htmlspecialchars($ai['description']) ?></p><?php endif; ?>
            <div class="fs-xs text-muted" style="margin-left:30px;">
              <?php if ($ai['presenter']): ?>Presenter: <strong><?= htmlspecialchars($ai['presenter']) ?></strong> · <?php endif; ?>
              <?= $ai['duration_minutes'] ?> min
            </div>
            <?php if ($ai['outcome']): ?><div style="margin-top:.4rem;margin-left:30px;padding:.5rem .7rem;background:#f0f7ff;border-radius:4px;font-size:.8rem;"><strong>Outcome:</strong> <?= htmlspecialchars($ai['outcome']) ?></div><?php endif; ?>
          </div>
          <button class="btn btn-sm btn-outline-secondary no-print"
                  onclick="updateAgenda(<?= $ai['id'] ?>,<?= $viewId ?>,'<?= htmlspecialchars($ai['status']) ?>','<?= htmlspecialchars(str_replace("'","\\'",$ai['outcome']??'')) ?>')">
            Update
          </button>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?><div class="empty-state" style="padding:1.5rem;"><p>No agenda items yet.</p></div><?php endif; ?>
    </div>
  </div>

  <!-- Resolutions -->
  <div class="card" id="resolutions">
    <div class="card-header">
      <h5><span class="ch-icon">◎</span> Resolutions (<?= count($resolutions) ?>)</h5>
      <button class="btn btn-sm btn-primary" data-modal="addResolutionModal">+ Add Resolution</button>
    </div>
    <div class="card-body p-0">
      <?php if ($resolutions): ?>
      <?php foreach ($resolutions as $res): ?>
      <div style="padding:1rem 1.2rem;border-bottom:1px solid var(--border);">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.8rem;">
          <div style="flex:1;">
            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.4rem;">
              <span class="fw-700 text-primary"><?= htmlspecialchars($res['resolution_number']) ?></span>
              <?= getStatusBadge($res['status']) ?>
              <span class="badge badge-<?= $res['priority']==='urgent'?'danger':($res['priority']==='high'?'warning':'secondary') ?>"><?= ucfirst($res['priority']) ?></span>
            </div>
            <div class="fw-600 fs-sm" style="margin-bottom:.3rem;"><?= htmlspecialchars($res['title']) ?></div>
            <p class="fs-sm" style="color:#555;margin-bottom:.4rem;"><?= htmlspecialchars($res['resolution_text']) ?></p>
            <div class="fs-xs text-muted">
              <?php if ($res['dept_name']): ?>Dept: <strong><?= htmlspecialchars($res['dept_name']) ?></strong> · <?php endif; ?>
              <?php if ($res['responsible_officer']): ?>Officer: <strong><?= htmlspecialchars($res['responsible_officer']) ?></strong> · <?php endif; ?>
              <?php if ($res['target_date']): ?>Target: <strong><?= formatDate($res['target_date']) ?></strong><?php endif; ?>
            </div>
            <?php if ($res['implementation_notes']): ?><div style="margin-top:.4rem;padding:.5rem .7rem;background:#f0fff4;border-radius:4px;font-size:.8rem;"><strong>Progress:</strong> <?= htmlspecialchars($res['implementation_notes']) ?></div><?php endif; ?>
          </div>
          <button class="btn btn-sm btn-outline-primary no-print"
                  onclick="updateResolution(<?= htmlspecialchars(json_encode($res)) ?>)">
            Update
          </button>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?><div class="empty-state" style="padding:1.5rem;"><p>No resolutions recorded yet.</p></div><?php endif; ?>
    </div>
  </div>
</div>

<!-- Right: Attendees + Actions -->
<div>
  <div class="card" id="attendees" style="margin-bottom:1rem;">
    <div class="card-header">
      <h5><span class="ch-icon">◉</span> Attendees (<?= count(array_filter($attendees,fn($a)=>$a['attended'])) ?> present)</h5>
      <button class="btn btn-sm btn-primary" data-modal="addAttendeeModal">+ Add</button>
    </div>
    <div class="card-body p-0">
      <?php if ($attendees): ?>
      <div class="table-wrapper">
        <table class="tcms-table table-sm">
          <thead><tr><th>Name</th><th>Designation</th><th>Dept</th><th>Present</th></tr></thead>
          <tbody>
          <?php foreach ($attendees as $att): ?>
          <tr>
            <td class="fw-600 fs-sm"><?= htmlspecialchars($att['name']) ?></td>
            <td class="fs-xs"><?= htmlspecialchars($att['designation']??'') ?></td>
            <td class="fs-xs"><?= htmlspecialchars($att['department']??'') ?></td>
            <td class="text-center">
              <?php if ($att['apology']): ?><span class="badge badge-warning">Apology</span>
              <?php elseif ($att['attended']): ?><span class="badge badge-success">✓</span>
              <?php else: ?><span class="badge badge-secondary">Absent</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><div class="empty-state" style="padding:1.5rem;"><p>No attendees added yet.</p></div><?php endif; ?>
    </div>
  </div>
</div>

</div>

<!-- ── MODALS ─────────────────────────────────────────────────── -->

<!-- Edit Meeting Modal -->
<div class="modal-backdrop" id="editMeetingModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Edit Meeting Details</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="update_meeting"><input type="hidden" name="meeting_id" value="<?= $viewId ?>">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group" style="grid-column:span 2;"><label class="form-label">Title <span class="req">*</span></label><input type="text" name="title" class="form-control" required value="<?= htmlspecialchars($meeting['title']) ?>"></div>
          <div class="form-group"><label class="form-label">Venue</label><input type="text" name="venue" class="form-control" value="<?= htmlspecialchars($meeting['venue']??'') ?>"></div>
          <div class="form-group"><label class="form-label">Meeting Date</label><input type="date" name="meeting_date" class="form-control" value="<?= $meeting['meeting_date'] ?>"></div>
          <div class="form-group"><label class="form-label">Start Time</label><input type="time" name="start_time" class="form-control" value="<?= $meeting['start_time']??'' ?>"></div>
          <div class="form-group"><label class="form-label">End Time</label><input type="time" name="end_time" class="form-control" value="<?= $meeting['end_time']??'' ?>"></div>
          <div class="form-group"><label class="form-label">Chairperson</label><input type="text" name="chairperson" class="form-control" value="<?= htmlspecialchars($meeting['chairperson']??'') ?>"></div>
          <div class="form-group"><label class="form-label">Secretary</label><input type="text" name="secretary" class="form-control" value="<?= htmlspecialchars($meeting['secretary']??'') ?>"></div>
          <div class="form-group"><label class="form-label">Number of Attendees</label><input type="number" name="attendees_count" class="form-control" min="0" value="<?= $meeting['attendees_count'] ?>"></div>
          <div class="form-group"><label class="form-label">Status</label>
            <select name="status" class="form-select">
              <?php foreach (['scheduled','in_progress','completed','cancelled','adjourned'] as $s): ?>
              <option value="<?= $s ?>" <?= $meeting['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Next Meeting Date</label><input type="date" name="next_meeting_date" class="form-control" value="<?= $meeting['next_meeting_date']??'' ?>"></div>
          <div class="form-group"><label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;margin-top:1.6rem;"><input type="checkbox" name="quorum_met" <?= $meeting['quorum_met']?'checked':'' ?>> Quorum was met</label></div>
        </div>
        <div class="form-group"><label class="form-label">Meeting Summary / Notes</label><textarea name="summary" class="form-control" rows="4"><?= htmlspecialchars($meeting['summary']??'') ?></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div>
    </form>
  </div>
</div>

<!-- Add Agenda Modal -->
<div class="modal-backdrop" id="addAgendaModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Add Agenda Item</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="add_agenda"><input type="hidden" name="meeting_id" value="<?= $viewId ?>">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Title <span class="req">*</span></label><input type="text" name="title" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
        <div class="grid-2">
          <div class="form-group"><label class="form-label">Presenter</label><input type="text" name="presenter" class="form-control"></div>
          <div class="form-group"><label class="form-label">Duration (minutes)</label><input type="number" name="duration_minutes" class="form-control" min="1" value="15"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Add Item</button></div>
    </form>
  </div>
</div>

<!-- Update Agenda Modal -->
<div class="modal-backdrop" id="updateAgendaModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Update Agenda Item</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="update_agenda">
      <input type="hidden" name="agenda_id" id="ua_agenda_id"><input type="hidden" name="meeting_id" value="<?= $viewId ?>">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Status</label>
          <select name="status" id="ua_status" class="form-select">
            <option value="pending">Pending</option><option value="discussed">Discussed</option>
            <option value="deferred">Deferred</option><option value="withdrawn">Withdrawn</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Outcome / Decision</label><textarea name="outcome" id="ua_outcome" class="form-control" rows="4" placeholder="Record the decision or outcome of this agenda item..."></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Update</button></div>
    </form>
  </div>
</div>

<!-- Add Attendee Modal -->
<div class="modal-backdrop" id="addAttendeeModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Add Attendee</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="add_attendee"><input type="hidden" name="meeting_id" value="<?= $viewId ?>">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Name <span class="req">*</span></label><input type="text" name="name" class="form-control" required></div>
        <div class="grid-2">
          <div class="form-group"><label class="form-label">Designation</label><input type="text" name="designation" class="form-control"></div>
          <div class="form-group"><label class="form-label">Department / Organisation</label><input type="text" name="department" class="form-control"></div>
          <div class="form-group"><label class="form-label">Type</label>
            <select name="attendee_type" class="form-select"><option value="staff">Staff</option><option value="councillor">Councillor</option><option value="external">External</option></select>
          </div>
        </div>
        <div style="display:flex;gap:1.5rem;margin-top:.5rem;">
          <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;"><input type="checkbox" name="attended" value="1" checked> Attended</label>
          <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;"><input type="checkbox" name="apology" value="1"> Sent Apology</label>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Add Attendee</button></div>
    </form>
  </div>
</div>

<!-- Add Resolution Modal -->
<div class="modal-backdrop" id="addResolutionModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Record Council Resolution</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="add_resolution"><input type="hidden" name="meeting_id" value="<?= $viewId ?>">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Resolution Title <span class="req">*</span></label><input type="text" name="title" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Resolution Text <span class="req">*</span></label><textarea name="resolution_text" class="form-control" rows="4" required placeholder="RESOLVED THAT..."></textarea></div>
        <div class="grid-2">
          <div class="form-group"><label class="form-label">Related Agenda Item</label>
            <select name="agenda_id" class="form-select"><option value="">—</option>
              <?php foreach ($agendaForRes as $ai): ?><option value="<?= $ai['id'] ?>"><?= $ai['item_number'] ?>. <?= htmlspecialchars(substr($ai['title'],0,50)) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Priority</label>
            <select name="priority" class="form-select"><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select>
          </div>
          <div class="form-group"><label class="form-label">Responsible Department</label>
            <select name="responsible_dept" class="form-select"><option value="">—</option>
              <?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Responsible Officer</label><input type="text" name="responsible_officer" class="form-control"></div>
          <div class="form-group"><label class="form-label">Target Completion Date</label><input type="date" name="target_date" class="form-control"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Record Resolution</button></div>
    </form>
  </div>
</div>

<!-- Update Resolution Modal -->
<div class="modal-backdrop" id="updateResolutionModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Update Resolution</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="update_resolution">
      <input type="hidden" name="resolution_id" id="ur_id"><input type="hidden" name="meeting_id" value="<?= $viewId ?>">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Status</label>
          <select name="status" id="ur_status" class="form-select">
            <?php foreach ($statusOpts as $s): ?><option value="<?= $s ?>"><?= ucfirst(str_replace('_',' ',$s)) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Implementation Date</label><input type="date" name="implemented_date" id="ur_date" class="form-control"></div>
        <div class="form-group"><label class="form-label">Implementation Notes / Progress Update</label><textarea name="implementation_notes" id="ur_notes" class="form-control" rows="4"></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Update Resolution</button></div>
    </form>
  </div>
</div>

<!-- Upload Minutes Modal -->
<div class="modal-backdrop" id="uploadMinutesModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Upload Meeting Minutes</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST" enctype="multipart/form-data">
      <?= csrfField() ?><input type="hidden" name="action" value="upload_minutes"><input type="hidden" name="meeting_id" value="<?= $viewId ?>">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Minutes File <span class="req">*</span></label>
          <input type="file" name="minutes_file" class="form-control" required accept=".pdf,.docx">
          <div class="form-text">PDF or DOCX. Max 10MB.</div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Upload Minutes</button></div>
    </form>
  </div>
</div>

    <?php
    renderPageEnd(); echo '</div></div>';
    ?>
<script>
function updateAgenda(id, mid, status, outcome) {
    document.getElementById('ua_agenda_id').value = id;
    document.getElementById('ua_status').value    = status;
    document.getElementById('ua_outcome').value   = outcome;
    openModal('updateAgendaModal');
}
function updateResolution(r) {
    document.getElementById('ur_id').value     = r.id;
    document.getElementById('ur_status').value = r.status;
    document.getElementById('ur_notes').value  = r.implementation_notes || '';
    document.getElementById('ur_date').value   = r.implemented_date || '';
    openModal('updateResolutionModal');
}
</script>
    <?php
    renderFooter(); exit;
}

// ══════════════════════════════════════════════════════════════════
// LIST VIEW
// ══════════════════════════════════════════════════════════════════
$typeFil   = $_GET['type']   ?? '';
$statusFil = $_GET['status'] ?? '';
$tab       = $_GET['tab']    ?? 'meetings';
$page      = max(1,(int)($_GET['page']??1));

$where = ['1=1']; $params = [];
if ($typeFil)   { $where[] = "m.meeting_type=?";  $params[] = $typeFil; }
if ($statusFil) { $where[] = "m.status=?";         $params[] = $statusFil; }
$wSQL = implode(' AND ',$where);

$cnt = $db->prepare("SELECT COUNT(*) FROM council_meetings m WHERE $wSQL"); $cnt->execute($params); $total=(int)$cnt->fetchColumn();
$pg  = paginate($total,$page);
$rows = $db->prepare("SELECT m.*, u.full_name AS created_by_name,
    (SELECT COUNT(*) FROM council_resolutions WHERE meeting_id=m.id) AS res_count,
    (SELECT COUNT(*) FROM council_resolutions WHERE meeting_id=m.id AND status='pending') AS pending_res
    FROM council_meetings m JOIN users u ON m.created_by=u.id
    WHERE $wSQL ORDER BY m.meeting_date DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute($params); $meetings = $rows->fetchAll();

// Pending resolutions (all meetings)
$pendingRes = $db->query("SELECT r.*, m.meeting_number, m.meeting_date, d.name AS dept_name
    FROM council_resolutions r JOIN council_meetings m ON r.meeting_id=m.id
    LEFT JOIN departments d ON r.responsible_dept=d.id
    WHERE r.status IN ('pending','in_progress') ORDER BY r.priority DESC, r.target_date ASC LIMIT 50")->fetchAll();

// Summary stats
$totalMeetings = (int)$db->query("SELECT COUNT(*) FROM council_meetings")->fetchColumn();
$totalRes      = (int)$db->query("SELECT COUNT(*) FROM council_resolutions")->fetchColumn();
$pendingResCount=(int)$db->query("SELECT COUNT(*) FROM council_resolutions WHERE status='pending'")->fetchColumn();
$overdueRes    = (int)$db->query("SELECT COUNT(*) FROM council_resolutions WHERE status='pending' AND target_date < CURDATE()")->fetchColumn();

renderHead('Council Meetings');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Council Meetings & Resolutions','Schedule meetings, record minutes and track resolutions');
renderPageStart('Council Meetings & Resolutions','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Meetings'],
]);
renderPageActions('<button class="btn btn-primary" data-modal="createMeetingModal">+ Schedule Meeting</button>');
renderFlashMessages();
?>

<div class="grid-4" style="margin-bottom:1.2rem;">
  <div class="stat-card"><div class="stat-icon">◎</div><div class="stat-info"><div class="label">Total Meetings</div><div class="value"><?= $totalMeetings ?></div></div></div>
  <div class="stat-card blue"><div class="stat-icon">◆</div><div class="stat-info"><div class="label">Total Resolutions</div><div class="value"><?= $totalRes ?></div></div></div>
  <div class="stat-card amber"><div class="stat-icon">⏳</div><div class="stat-info"><div class="label">Pending</div><div class="value"><?= $pendingResCount ?></div></div></div>
  <div class="stat-card red"><div class="stat-icon">⚠</div><div class="stat-info"><div class="label">Overdue Resolutions</div><div class="value"><?= $overdueRes ?></div></div></div>
</div>

<div class="tab-group">
  <div class="tab-nav">
    <button class="tab-link <?= $tab==='meetings'?'active':'' ?>" data-tab="meetingsTab">Meetings</button>
    <button class="tab-link <?= $tab==='resolutions'?'active':'' ?>" data-tab="resolutionsTab">
      Pending Resolutions <?php if ($pendingResCount>0): ?><span class="nav-badge"><?= $pendingResCount ?></span><?php endif; ?>
    </button>
  </div>
</div>

<!-- Meetings Tab -->
<div id="meetingsTab" class="tab-panel <?= $tab==='meetings'?'active':'' ?>">
  <form method="GET" style="margin-bottom:1rem;">
  <div class="filter-row">
    <div class="form-group"><label>Type</label>
      <select name="type" class="form-select"><option value="">All Types</option>
        <?php foreach (['full_council','executive','committee','departmental','extraordinary'] as $t): ?>
        <option value="<?= $t ?>" <?= $typeFil===$t?'selected':'' ?>><?= ucwords(str_replace('_',' ',$t)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>Status</label>
      <select name="status" class="form-select"><option value="">All</option>
        <?php foreach (['scheduled','completed','cancelled','adjourned'] as $s): ?>
        <option value="<?= $s ?>" <?= $statusFil===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>&nbsp;</label><div style="display:flex;gap:.4rem;"><button type="submit" class="btn btn-primary">Filter</button><a href="meetings.php" class="btn btn-outline-secondary">Reset</a></div></div>
  </div>
  </form>

  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◎</span> Meetings (<?= number_format($total) ?>)</h5></div>
    <div class="card-body p-0">
      <?php if ($meetings): ?>
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Ref.</th><th>Title</th><th>Type</th><th>Date</th><th>Venue</th><th>Attendees</th><th>Resolutions</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($meetings as $m): ?>
          <tr>
            <td class="fw-700 text-primary"><?= htmlspecialchars($m['meeting_number']) ?></td>
            <td>
              <div class="fw-600"><?= htmlspecialchars(substr($m['title'],0,40)) ?></div>
              <?php if ($m['chairperson']): ?><div class="fs-xs text-muted">Chair: <?= htmlspecialchars($m['chairperson']) ?></div><?php endif; ?>
            </td>
            <td><span class="badge badge-info"><?= ucwords(str_replace('_',' ',$m['meeting_type'])) ?></span></td>
            <td class="fs-sm"><?= formatDate($m['meeting_date']) ?></td>
            <td class="fs-sm"><?= htmlspecialchars($m['venue']??'—') ?></td>
            <td class="text-center"><?= $m['attendees_count'] ?></td>
            <td class="text-center">
              <?= $m['res_count'] ?>
              <?php if ($m['pending_res']>0): ?><span class="badge badge-warning" style="margin-left:.3rem;"><?= $m['pending_res'] ?> pending</span><?php endif; ?>
            </td>
            <td><?= getStatusBadge($m['status']) ?></td>
            <td><a href="?view=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">Open</a></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><div class="empty-state"><div class="empty-icon">◎</div><h5>No meetings found</h5><p>Schedule a meeting using the button above.</p></div><?php endif; ?>
    </div>
    <?php if ($pg['total_pages']>1): ?><div class="card-footer"><?= renderPagination($pg,'?tab=meetings&type='.urlencode($typeFil).'&status='.urlencode($statusFil)) ?></div><?php endif; ?>
  </div>
</div>

<!-- Pending Resolutions Tab -->
<div id="resolutionsTab" class="tab-panel <?= $tab==='resolutions'?'active':'' ?>">
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◆</span> Pending &amp; In-Progress Resolutions</h5></div>
    <div class="card-body p-0">
      <?php if ($pendingRes): ?>
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Resolution No.</th><th>Title</th><th>Meeting</th><th>Department</th><th>Officer</th><th>Target Date</th><th>Priority</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach ($pendingRes as $r):
            $isOverdue = $r['target_date'] && strtotime($r['target_date']) < time() && $r['status']==='pending'; ?>
          <tr style="<?= $isOverdue?'background:rgba(189,33,48,.03);':'' ?>">
            <td class="fw-700 text-primary"><?= htmlspecialchars($r['resolution_number']) ?></td>
            <td><div class="fw-600"><?= htmlspecialchars(substr($r['title'],0,40)) ?></div><div class="fs-xs text-muted"><?= htmlspecialchars(substr($r['resolution_text'],0,60)) ?>...</div></td>
            <td class="fs-sm"><a href="?view=<?= $r['meeting_id'] ?>" class="text-primary"><?= htmlspecialchars($r['meeting_number']) ?></a></td>
            <td class="fs-sm"><?= htmlspecialchars($r['dept_name']??'—') ?></td>
            <td class="fs-sm"><?= htmlspecialchars($r['responsible_officer']??'—') ?></td>
            <td class="fs-sm <?= $isOverdue?'text-danger fw-700':'' ?>"><?= $r['target_date']?formatDate($r['target_date']):'—' ?><?= $isOverdue?' ⚠':'' ?></td>
            <td><span class="badge badge-<?= $r['priority']==='urgent'?'danger':($r['priority']==='high'?'warning':'secondary') ?>"><?= ucfirst($r['priority']) ?></span></td>
            <td><?= getStatusBadge($r['status']) ?></td>
            <td><a href="?view=<?= $r['meeting_id'] ?>#resolutions" class="btn btn-sm btn-outline-primary">Update</a></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><div class="empty-state"><div class="empty-icon">✅</div><h5>All resolutions are implemented</h5></div><?php endif; ?>
    </div>
  </div>
</div>

<!-- Create Meeting Modal -->
<div class="modal-backdrop" id="createMeetingModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Schedule Council Meeting</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="create_meeting">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group" style="grid-column:span 2;"><label class="form-label">Meeting Title <span class="req">*</span></label><input type="text" name="title" class="form-control" required placeholder="e.g. Full Council Meeting — October 2026"></div>
          <div class="form-group"><label class="form-label">Meeting Type <span class="req">*</span></label>
            <select name="meeting_type" class="form-select" required>
              <?php foreach (['full_council'=>'Full Council','executive'=>'Executive Committee','committee'=>'Committee Meeting','departmental'=>'Departmental Meeting','extraordinary'=>'Extraordinary Meeting','other'=>'Other'] as $v=>$l): ?>
              <option value="<?= $v ?>"><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Date <span class="req">*</span></label><input type="date" name="meeting_date" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
          <div class="form-group"><label class="form-label">Start Time</label><input type="time" name="start_time" class="form-control" value="09:00"></div>
          <div class="form-group"><label class="form-label">Venue</label><input type="text" name="venue" class="form-control" placeholder="e.g. Council Chambers"></div>
          <div class="form-group"><label class="form-label">Chairperson</label><input type="text" name="chairperson" class="form-control"></div>
          <div class="form-group"><label class="form-label">Secretary</label><input type="text" name="secretary" class="form-control"></div>
        </div>
        <div class="form-group"><label class="form-label">Agenda (brief)</label><textarea name="agenda" class="form-control" rows="3" placeholder="List agenda items..."></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Schedule Meeting</button></div>
    </form>
  </div>
</div>

<?php
renderPageEnd(); echo '</div></div>';
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var tabParam = new URLSearchParams(window.location.search).get('tab');
  if (tabParam === 'resolutions') {
    document.querySelectorAll('.tab-link').forEach(l=>l.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
    document.querySelector('[data-tab="resolutionsTab"]').classList.add('active');
    document.getElementById('resolutionsTab').classList.add('active');
  }
});
</script>
<?php renderFooter(); ?>
