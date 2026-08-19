<?php
// ============================================================
// File: admin/report_print_footer.php
// Closes the print sheet: signature block + footer + auto-print.
// The including file may set $signRolePrepared / $signRoleApproved.
// ============================================================

if (!isset($signRolePrepared)) $signRolePrepared = 'Prepared by';
if (!isset($signRoleApproved)) $signRoleApproved = 'Approved by';

$preparedName = '';
if (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $ur  = $conn->query("SELECT full_name FROM users WHERE id={$uid} LIMIT 1");
    if ($ur && $urow = $ur->fetch_assoc()) $preparedName = $urow['full_name'] ?? '';
}
?>

<!-- Signature block -->
<div class="rp-signatures">
    <div class="rp-sig">
        <div class="rp-sig-line">
            <div class="rp-sig-name"><?= $preparedName ? htmlspecialchars($preparedName) : '&nbsp;' ?></div>
            <div class="rp-sig-role"><?= htmlspecialchars($signRolePrepared) ?></div>
        </div>
    </div>
    <div class="rp-sig">
        <div class="rp-sig-line">
            <div class="rp-sig-name">&nbsp;</div>
            <div class="rp-sig-role"><?= htmlspecialchars($signRoleApproved) ?></div>
        </div>
    </div>
</div>

<!-- Footer -->
<div class="rp-foot">
    <div>Hiney's Eggs &amp; Live Chicken Business — Internal Report</div>
    <div>Printed <?= date('M j, Y g:i A') ?></div>
</div>

</div><!-- /.sheet -->

<script>
    // Auto-open the print dialog once the page has rendered.
    window.addEventListener('load', function() {
        // small delay so fonts/layout settle before the dialog snapshots the page
        setTimeout(function() {
            window.print();
        }, 400);
    });
</script>
</body>

</html>