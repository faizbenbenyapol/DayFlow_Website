<div class="page-header flex items-center justify-between">
    <div>
        <h1 class="page-title">AI สร้างคอนเทนต์</h1>
        <div class="text-xs text-muted">สร้างสคริปต์ + วิดีโอสั้นสำหรับ TikTok / YouTube Shorts พร้อมชื่อคลิป + แฮชแท็กพร้อมโพสต์</div>
    </div>
</div>

<div class="flex gap-3 mb-8 ai-tabs" style="flex-wrap:wrap">
    <button class="btn btn-primary btn-sm ai-tab active" data-tab="generate">สร้างคอนเทนต์</button>
    <button class="btn btn-ghost btn-sm ai-tab" data-tab="keys">ตั้งค่า API Key</button>
    <button class="btn btn-ghost btn-sm ai-tab" data-tab="history">ประวัติ</button>
</div>

<!-- ============ TAB: GENERATE ============ -->
<div id="ai-pane-generate" class="ai-pane">
    <div class="card mb-6">
        <div class="card-header"><span class="card-title">1. ใส่หัวข้อ / คีย์เวิร์ด</span></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">หัวข้อคลิป</label>
                <input type="text" class="form-control" id="aiKeyword"
                       placeholder="เช่น ทริคออมเงินสำหรับวัยเริ่มทำงาน, สูตรเบาๆ ลดพุง 7 วัน, ทิปทำงาน WFH">
                <div class="ai-preset-chips" id="aiPresetChips">
                    <span class="ai-chip-label">ไอเดีย:</span>
                    <button type="button" class="ai-chip" data-preset="ทริคออมเงินสำหรับวัยเริ่มทำงาน">ออมเงิน</button>
                    <button type="button" class="ai-chip" data-preset="สูตรอาหารเบาๆ ลดพุง 7 วัน">ลดพุง</button>
                    <button type="button" class="ai-chip" data-preset="ทิปทำงาน WFH ให้ได้ผลงาน">WFH</button>
                    <button type="button" class="ai-chip" data-preset="5 แอปที่ทุกคนต้องมีในมือถือ">แอปเด็ด</button>
                    <button type="button" class="ai-chip" data-preset="เทคนิคพูดให้คนฟัง">พูดเก่ง</button>
                    <button type="button" class="ai-chip" data-preset="ลงทุนมือใหม่เริ่มยังไง">ลงทุน</button>
                    <button type="button" class="ai-chip" data-preset="เที่ยวไทยงบ 1,000 บาท">เที่ยวถูก</button>
                    <button type="button" class="ai-chip" data-preset="สกินแคร์ตอนเช้า 3 ขั้นตอน">สกินแคร์</button>
                </div>
            </div>

            <details class="mb-4">
                <summary class="text-sm text-muted" style="cursor:pointer">ปรับแต่งเพิ่มเติม (ไม่บังคับ)</summary>
                <div class="form-row mt-4">
                    <div class="form-group">
                        <label class="form-label">แพลตฟอร์ม</label>
                        <select class="form-control" id="aiPlatform">
                            <option value="tiktok">TikTok</option>
                            <option value="shorts">YouTube Shorts</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">สไตล์</label>
                        <select class="form-control" id="aiStyle">
                            <option value="informative">ให้ข้อมูล/ทิปส์</option>
                            <option value="funny">สนุก/ตลก</option>
                            <option value="inspiring">สร้างแรงบันดาลใจ</option>
                            <option value="educational">สอน/ให้ความรู้</option>
                            <option value="storytelling">เล่าเรื่อง</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ภาษา</label>
                        <select class="form-control" id="aiLanguage">
                            <option value="th">ไทย</option>
                            <option value="en">อังกฤษ</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ความยาว (วินาที)</label>
                        <input type="number" min="15" max="90" step="5" class="form-control" id="aiDuration" value="30">
                    </div>
                    <div class="form-group">
                        <label class="form-label">AI Text Provider</label>
                        <select class="form-control" id="aiTextProvider">
                            <option value="openai">OpenAI (GPT-4o-mini)</option>
                            <option value="gemini">Google Gemini</option>
                            <option value="anthropic">Anthropic Claude</option>
                            <option value="kimi">Moonshot Kimi</option>
                            <option value="openrouter">OpenRouter.ai</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">คำขอเพิ่มเติม</label>
                    <textarea class="form-control" id="aiExtra" rows="2"
                              placeholder="เช่น เน้นกลุ่มเป้าหมายนักศึกษา, อย่าใช้ศัพท์ยาก, ใส่มุกที่เกี่ยวกับแมว"></textarea>
                </div>
            </details>

            <div class="flex ai-gen-actions" style="gap:.5rem; flex-wrap:wrap">
                <button class="btn btn-primary" id="btnGenScript">
                    <span class="ai-btn-label">สร้างสคริปต์</span>
                </button>
                <button class="btn btn-ghost" id="btnClearGen">ล้าง</button>
                <span class="text-xs text-muted" style="margin-left:auto;align-self:center">
                    <kbd>Ctrl</kbd>+<kbd>Enter</kbd> = สร้าง
                </span>
            </div>
        </div>
    </div>

    <!-- Skeleton loader -->
    <div id="aiSkeleton" class="card mb-6" hidden>
        <div class="card-body">
            <div class="ai-skel ai-skel-line" style="width:60%"></div>
            <div class="ai-skel ai-skel-line" style="width:90%"></div>
            <div class="ai-skel ai-skel-line" style="width:75%"></div>
            <div class="ai-skel ai-skel-block"></div>
            <div class="ai-skel ai-skel-line" style="width:40%"></div>
            <div class="text-xs text-muted text-center mt-4">AI กำลังคิด... (โดยทั่วไปใช้เวลา 10-30 วินาที)</div>
        </div>
    </div>

    <!-- Result -->
    <div id="aiResultCard" class="card mb-6" hidden>
        <div class="card-header">
            <span class="card-title">2. ผลลัพธ์สคริปต์</span>
            <button class="btn btn-ghost btn-sm" id="btnCopyAll">คัดลอกทั้งหมด</button>
        </div>
        <div class="card-body" id="aiResultBody"></div>
    </div>

    <!-- Video gen -->
    <div id="aiVideoCard" class="card" hidden>
        <div class="card-header"><span class="card-title">3. สร้างวิดีโอ (ใช้ Replicate)</span></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Prompt สำหรับวิดีโอ (ภาษาอังกฤษ)</label>
                <textarea class="form-control" id="aiVideoPrompt" rows="3"
                          placeholder="Cinematic shot of ..."></textarea>
                <p class="form-hint">ระบบจะใช้ visual prompt จากฉากแรกของสคริปต์โดยอัตโนมัติ แก้ไขได้ตามต้องการ</p>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">โมเดล Replicate</label>
                    <select class="form-control" id="aiVideoModel">
                        <option value="minimax/video-01">minimax/video-01 (6s, เริ่มต้นแนะนำ)</option>
                        <option value="bytedance/seedance-1-lite">bytedance/seedance-1-lite (ประหยัด)</option>
                        <option value="kwaivgi/kling-v2.0">kwaivgi/kling-v2.0 (คุณภาพสูง, แพง)</option>
                        <option value="google/veo-3">google/veo-3 (พรีเมียม)</option>
                    </select>
                    <p class="form-hint" style="margin-top:var(--space-2)">
                        ทุกโมเดลสร้างวิดีโอด้านบน (เช่น minimax, seedance, kling, veo-3) ทำงานอยู่ภายใต้ผู้ให้บริการ **Replicate** ร่วมกันทั้งหมด คุณสามารถตั้งค่า API Key ของ Replicate ได้ที่แท็บ "ตั้งค่า API Key" ด้านบน เพื่อใช้งานโมเดลเหล่านี้
                    </p>
                </div>
            </div>
            <button class="btn btn-primary" id="btnGenVideo">สร้างวิดีโอ</button>
            <div id="aiVideoStatus" class="mt-4"></div>
            <div id="aiVideoPlayer" class="mt-4"></div>
        </div>
    </div>
</div>

<!-- ============ TAB: KEYS ============ -->
<div id="ai-pane-keys" class="ai-pane" style="display:none">
    <div class="card" style="max-width:720px">
        <div class="card-header"><span class="card-title">API Keys</span></div>
        <div class="card-body">
            <p class="form-hint" style="margin-bottom:1rem">
                API Key ของคุณจะถูกเข้ารหัสก่อนเก็บลงฐานข้อมูล (AES-256-CBC + HMAC) และไม่มีใครเข้าถึงได้นอกจากบัญชีของคุณ
            </p>
            <div id="aiKeysList"></div>
        </div>
    </div>

    <div class="card mt-6" style="max-width:720px">
        <div class="card-header"><span class="card-title">วิธีขอ API Key</span></div>
        <div class="card-body">
            <ul class="ai-help-list">
                <li><strong>OpenAI</strong> (gen สคริปต์): <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com/api-keys</a></li>
                <li><strong>Google Gemini</strong> (gen สคริปต์, มี free tier): <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">aistudio.google.com/app/apikey</a></li>
                <li><strong>Anthropic Claude</strong> (gen สคริปต์): <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com/settings/keys</a></li>
                <li><strong>Moonshot Kimi AI</strong> (gen สคริปต์): <a href="https://platform.moonshot.cn/" target="_blank" rel="noopener">platform.moonshot.cn</a></li>
                <li><strong>OpenRouter.ai</strong> (gen สคริปต์): <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a></li>
                <li><strong>Replicate</strong> (gen วิดีโอสำหรับ Minimax, Kling, Veo-3 ฯลฯ): <a href="https://replicate.com/account/api-tokens" target="_blank" rel="noopener">replicate.com/account/api-tokens</a></li>
            </ul>
        </div>
    </div>
</div>

<!-- ============ TAB: HISTORY ============ -->
<div id="ai-pane-history" class="ai-pane" style="display:none">
    <div class="card">
        <div class="card-header"><span class="card-title">ประวัติการสร้าง</span></div>
        <div class="card-body">
            <div class="form-group mb-4">
                <input type="text" class="form-control" id="aiHistorySearch" placeholder="ค้นหาในประวัติ...">
            </div>
            <div id="aiHistoryList"><div class="text-xs text-muted text-center">กำลังโหลด...</div></div>
        </div>
    </div>
</div>
