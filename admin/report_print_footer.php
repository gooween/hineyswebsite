<?php
// ============================================================
// File: admin/report_print_footer.php
// Closes the print sheet: signature block + footer + auto-print.
// The including file may set, for each of Prepared / Approved:
//   $signRolePrepared / $signRoleApproved   label, e.g. 'Prepared by'
//   $signNamePrepared / $signNameApproved   name printed under the signature line
//   $signPosPrepared  / $signPosApproved    position printed under the name
// The block is pinned to the bottom of the page (repeats on every printed page).
// ============================================================

if (!isset($signRolePrepared)) $signRolePrepared = 'Prepared by';
if (!isset($signRoleApproved)) $signRoleApproved = 'Approved by';
if (!isset($signPosPrepared))  $signPosPrepared  = 'Admin';
if (!isset($signPosApproved))  $signPosApproved  = '';

$preparedName = '';
if (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $ur  = $conn->query("SELECT full_name FROM users WHERE id={$uid} LIMIT 1");
    if ($ur && $urow = $ur->fetch_assoc()) $preparedName = $urow['full_name'] ?? '';
}
// An explicit name from the including file wins over the logged-in user's name.
if (isset($signNamePrepared) && $signNamePrepared !== '') $preparedName = $signNamePrepared;
$approvedName = (isset($signNameApproved) && $signNameApproved !== '') ? $signNameApproved : '';
?>

<!-- Signature block — pinned to the bottom of the page, never split across two pages -->
<style>
    /* The measurements below use the SAME sizes as the print layout, so the
       on-screen preview, the fit-to-page script and the printed page all agree. */
    body {
        font-size: 10px;
    }

    .sheet {
        padding: 10mm 12mm;
        display: flex;
        flex-direction: column;
    }

    .rp-head {
        padding-bottom: 8px;
        margin-bottom: 8px;
    }

    .rp-brand-name {
        font-size: 1.3rem;
    }

    .rp-title-block {
        margin-top: 8px;
    }

    .rp-meta {
        padding: 6px 0 8px;
        margin-bottom: 8px;
    }

    .rp-section-title {
        font-size: 0.82rem;
        margin: 12px 0 5px;
        padding-bottom: 3px;
    }

    table.rp-table {
        font-size: 0.72rem;
        margin-bottom: 4px;
    }

    table.rp-table thead th {
        padding: 4px 8px;
        font-size: 0.66rem;
    }

    table.rp-table tbody td {
        padding: 3px 8px;
    }

    table.rp-table tfoot td {
        padding: 5px 8px;
        font-size: 0.74rem;
    }

    .rp-empty {
        padding: 12px;
        margin: 4px 0;
    }

    /* margin-top:auto pushes the signatures to the bottom of the sheet (the
       sheet is one A4 page tall); padding-top keeps a minimum gap above them. */
    .rp-signatures {
        margin: auto 0 0;
        padding: 10mm 0 0;
        gap: 40px;
        flex-shrink: 0;
        break-inside: avoid;
        page-break-inside: avoid;
    }

    .rp-sig {
        break-inside: avoid;
        page-break-inside: avoid;
    }

    .rp-sig-label {
        font-size: 0.78rem;
        font-weight: 700;
    }

    .rp-sig-line {
        margin-top: 26px;
    }

    .rp-sig-pos {
        font-size: 0.74rem;
        color: #333;
    }
</style>
<div class="rp-signatures">
    <div class="rp-sig">
        <div class="rp-sig-label"><?= htmlspecialchars(ucwords($signRolePrepared)) ?>:</div>
        <div class="rp-sig-line">
            <div class="rp-sig-name"><?= $preparedName !== '' ? htmlspecialchars($preparedName) : '&nbsp;' ?></div>
            <div class="rp-sig-pos"><?= $signPosPrepared !== '' ? htmlspecialchars($signPosPrepared) : '&nbsp;' ?></div>
        </div>
    </div>
    <div class="rp-sig">
        <div class="rp-sig-label"><?= htmlspecialchars(ucwords($signRoleApproved)) ?>:</div>
        <div class="rp-sig-line">
            <div class="rp-sig-name"><?= $approvedName !== '' ? htmlspecialchars($approvedName) : '&nbsp;' ?></div>
            <div class="rp-sig-pos"><?= $signPosApproved !== '' ? htmlspecialchars($signPosApproved) : '&nbsp;' ?></div>
        </div>
    </div>
</div>

<!-- Footer -->

</div><!-- /.sheet -->

<script>
    // 1) Shrink the report just enough to fit ONE page (A4), never below 55%.
    // 2) Make the sheet exactly one page tall so the CSS pins the signatures to its bottom.
    // 3) Then auto-open the print dialog.
    (function() {
        var MM = 96 / 25.4;
        var PAGE_H = 296 * MM; // A4 is 297mm; 1mm of slack so rounding can't spill onto a 2nd page

        function fitToPage() {
            var sheet = document.querySelector('.sheet');
            if (!sheet) return;
            try {
                // Measure the natural (unscaled) height of the content.
                sheet.style.maxWidth = 'none';
                sheet.style.width = '210mm';
                sheet.style.zoom = 1;
                sheet.style.minHeight = '0';
                var natural = sheet.getBoundingClientRect().height;

                var z = 1;
                if (natural > PAGE_H) {
                    z = Math.max(0.55, (PAGE_H / natural) * 0.985);
                    sheet.style.zoom = z;
                    sheet.style.width = (210 / z) + 'mm'; // keep the real width at 210mm
                }
                // One page tall (in unscaled units, so after zoom it is 296mm):
                // the signatures sit at the bottom, or flow after the content if it is longer.
                sheet.style.minHeight = (296 / z) + 'mm';
            } catch (e) {}
        }

        window.addEventListener('load', function() {
            fitToPage();
            // small delay so fonts/layout settle before the dialog snapshots the page
            setTimeout(function() {
                window.print();
            }, 400);
        });
    })();
</script>
</body>

</html>