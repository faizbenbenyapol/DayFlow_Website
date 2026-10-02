<?php
// =====================================================
// views/quick/index.php — things to remember, one line each
//
// The line to type into is first; the list is below it. assets/js/quick.js
// draws the list from /api/quick-items.
// =====================================================
?>
<div class="page-head">
    <div>
        <h1>จดด่วน</h1>
        <p class="sub" id="quickTally" aria-live="polite">กำลังโหลด…</p>
    </div>
</div>

<div class="quick-page">
    <form class="quick-capture" id="quickForm">
        <label class="sr-only" for="quickInput">สิ่งที่ต้องจำ</label>
        <input id="quickInput" class="form-control" maxlength="500" autocomplete="off" placeholder="พิมพ์สิ่งที่ต้องจำ แล้วกด Enter">
        <span class="quick-count" id="quickCount" aria-hidden="true">0 / 500</span>
        <button class="btn btn-primary" type="submit">จดไว้</button>
    </form>

    <div id="quickList" aria-live="polite" aria-busy="true">
        <div class="skel-row"><span class="skel box"></span><span class="skel skel-w-60"></span></div>
        <div class="skel-row"><span class="skel box"></span><span class="skel skel-w-45"></span></div>
        <div class="skel-row"><span class="skel box"></span><span class="skel skel-w-52"></span></div>
    </div>
</div>
