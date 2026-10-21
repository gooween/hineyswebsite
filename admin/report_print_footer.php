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

<!-- Signature block — sits at the bottom of the page, never split across two pages -->
<style>
    .rp-signatures {
        margin: 10mm 0 0;
        padding: 0;
        gap: 40px;
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
        margin-top: 24px;
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
    // 2) Push the signatures down to the bottom of that page.
    // 3) Then auto-open the print dialog.
    (function() {
        // Page height in CSS px, with ~7mm of slack so print rounding can't spill onto a 2nd page.
        var PAGE_H = 290 * 96 / 25.4;

        function fitToPage() {
            var sheet = document.querySelector('.sheet');
            if (!sheet) return;
            try {
                var z = 1;
                sheet.style.maxWidth = 'none';
                sheet.style.width = '210mm';
                sheet.style.zoom = 1;
                var sig = document.querySelector('.rp-signatures');
                if (sig) sig.style.marginTop = '';

                for (var i = 0; i < 8; i++) {
                    var h = sheet.getBoundingClientRect().height;
                    if (h <= PAGE_H || z <= 0.55) break;
                    z = Math.max(0.55, z * (PAGE_H / h) * 0.99);
                    sheet.style.zoom = z;
                    sheet.style.width = (210 / z) + 'mm'; // keep the real width at 210mm
                }

                // Short report: add space above the signatures so they land at the page bottom.
                var finalH = sheet.getBoundingClientRect().height;
                if (sig && finalH < PAGE_H) {
                    var base = parseFloat(getComputedStyle(sig).marginTop) || 0;
                    sig.style.marginTop = (base + (PAGE_H - finalH) / z) + 'px';
                }
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