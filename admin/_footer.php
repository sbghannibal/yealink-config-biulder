<?php
$current_year = date('Y');
$start_year = 2026;
$year_display = ($current_year > $start_year) ? "$start_year - $current_year" : $start_year;
?>
    </main>
    <footer style="background:#f8f9fa; padding:20px; text-align:center; margin-top:40px; border-top:1px solid #dee2e6;">
        <p style="margin:0; color:#6c757d; font-size:14px;">
            Yealink Config Builder &copy; <?php echo $year_display; ?>
        </p>
    </footer>
</body>
</html>
