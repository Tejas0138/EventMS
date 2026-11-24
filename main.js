/**
 * EventMS - Gate Terminal Client-Side Controller
 * Optimized for Dark Slate Theme & Native Mobile Input
 */

/**
 * Shared helper to convert UTC timestamp/Date to Indian Standard Time (Asia/Kolkata)
 * Standard formats:
 * - Full date with year: "10 Nov 2026, 07:11 PM"
 * - Without year: "10 Nov, 07:11 PM"
 */
function formatIST(dateValue, options = {}) {
    if (!dateValue) return '';
    try {
        let dateObj;
        if (dateValue instanceof Date) {
            dateObj = dateValue;
        } else if (typeof dateValue === 'number') {
            dateObj = new Date(dateValue);
        } else {
            let str = String(dateValue).trim();
            if (str === '' || str === 'Recorded' || str === 'Just now' || str === 'N/A') return str;
            // If string doesn't have a timezone indicator (Z or +HH:mm or -HH:mm), treat as UTC
            if (!/[Zz]|[+-]\d{2}:?\d{2}$/.test(str)) {
                str = str.replace(' ', 'T') + 'Z';
            }
            dateObj = new Date(str);
        }
        if (isNaN(dateObj.getTime())) return String(dateValue);

        const formatter = new Intl.DateTimeFormat('en-IN', {
            timeZone: 'Asia/Kolkata',
            day: '2-digit',
            month: 'short',
            year: options.includeYear !== false ? 'numeric' : undefined,
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        });

        const parts = formatter.formatToParts(dateObj);
        let day = '', month = '', year = '', hour = '', minute = '', dayPeriod = '';
        for (const p of parts) {
            if (p.type === 'day') day = p.value;
            if (p.type === 'month') month = p.value;
            if (p.type === 'year') year = p.value;
            if (p.type === 'hour') hour = p.value;
            if (p.type === 'minute') minute = p.value;
            if (p.type === 'dayPeriod') dayPeriod = p.value.toUpperCase();
        }
        if (options.includeYear === false) {
            return `${day} ${month}, ${hour}:${minute} ${dayPeriod}`;
        }
        return `${day} ${month} ${year}, ${hour}:${minute} ${dayPeriod}`;
    } catch (e) {
        return String(dateValue);
    }
}
window.formatIST = formatIST;

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('gateCheckInForm');
    const mobileInput = document.getElementById('mobileInput');
    const digitCounter = document.getElementById('digitCounter');
    const submitBtn = document.getElementById('submitBtn');
    const submitDayLabel = document.getElementById('submitDayLabel');
    const resultContainer = document.getElementById('resultContainer');
    const dayInput = document.getElementById('dayInput');
    const day1Btn = document.getElementById('day1Btn');
    const day2Btn = document.getElementById('day2Btn');

    // Day Selector Switch Handler (Globally accessible on window.switchDay & window.setDay)
    const setDay = (day) => {
        const selectedDay = (parseInt(day, 10) === 2) ? 2 : 1;
        if (dayInput) {
            dayInput.value = selectedDay;
        }
        if (day1Btn) {
            if (selectedDay === 1) {
                day1Btn.classList.add('active');
                day1Btn.setAttribute('aria-pressed', 'true');
            } else {
                day1Btn.classList.remove('active');
                day1Btn.setAttribute('aria-pressed', 'false');
            }
        }
        if (day2Btn) {
            if (selectedDay === 2) {
                day2Btn.classList.add('active');
                day2Btn.setAttribute('aria-pressed', 'true');
            } else {
                day2Btn.classList.remove('active');
                day2Btn.setAttribute('aria-pressed', 'false');
            }
        }
        if (submitDayLabel) {
            submitDayLabel.textContent = `Day ${selectedDay}`;
        } else if (submitBtn) {
            submitBtn.innerHTML = `<i class="bi bi-shield-check me-2"></i> Verify &amp; Check In (<span id="submitDayLabel">Day ${selectedDay}</span>)`;
        }

        // Update URL query param without triggering page reload
        try {
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('day', selectedDay);
            window.history.replaceState(null, '', currentUrl.toString());
        } catch (e) {}

        // Clear previous result feedback on day switch
        if (resultContainer) {
            resultContainer.innerHTML = '';
        }

        // Dynamically update modal QR preview
        try {
            const modalLiveUrlText = document.getElementById('modalLiveUrlText');
            const modalQrImg = document.getElementById('modalQrImg');
            const modalDownloadPng = document.getElementById('modalDownloadPng');
            const dayUrl = window.location.origin + window.location.pathname + '?day=' + selectedDay;
            if (modalLiveUrlText) modalLiveUrlText.textContent = dayUrl;
            if (modalQrImg) modalQrImg.src = 'https://quickchart.io/qr?text=' + encodeURIComponent(dayUrl) + '&size=400&margin=2&format=svg';
            if (modalDownloadPng) modalDownloadPng.href = 'https://quickchart.io/qr?text=' + encodeURIComponent(dayUrl) + '&size=400&margin=2&format=png';
        } catch (e) {}

        if (mobileInput) {
            mobileInput.focus();
        }
    };

    // Expose globally
    window.setDay = setDay;
    window.switchDay = setDay;

    if (day1Btn) {
        day1Btn.addEventListener('click', (e) => {
            e.preventDefault();
            setDay(1);
        });
    }
    if (day2Btn) {
        day2Btn.addEventListener('click', (e) => {
            e.preventDefault();
            setDay(2);
        });
    }

    // Delegate click on track in case buttons re-render or nested clicks happen
    const daySelectorTrack = document.querySelector('.day-selector-track');
    if (daySelectorTrack) {
        daySelectorTrack.addEventListener('click', (e) => {
            const btn = e.target.closest('.day-pill-btn');
            if (btn && btn.dataset.day) {
                e.preventDefault();
                setDay(parseInt(btn.dataset.day, 10));
            }
        });
    }

    // Synthesized Audio Feedback for Gatekeeper Accessibility (Safe lazy init)
    let audioCtx = null;
    const playAudioFeedback = (type) => {
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) return;
            if (!audioCtx) {
                audioCtx = new AudioContextClass();
            }
            if (audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);

            if (type === 'success') {
                // Happy upward chime (587Hz -> 880Hz)
                osc.frequency.setValueAtTime(587.33, audioCtx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(880.00, audioCtx.currentTime + 0.15);
                gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.3);
                osc.start(audioCtx.currentTime);
                osc.stop(audioCtx.currentTime + 0.3);
            } else if (type === 'duplicate') {
                // Caution pulse (440Hz)
                osc.frequency.setValueAtTime(440, audioCtx.currentTime);
                gain.gain.setValueAtTime(0.25, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.25);
                osc.start(audioCtx.currentTime);
                osc.stop(audioCtx.currentTime + 0.25);
            } else {
                // Rejection tone (180Hz)
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(180, audioCtx.currentTime);
                gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.35);
                osc.start(audioCtx.currentTime);
                osc.stop(audioCtx.currentTime + 0.35);
            }
        } catch (e) {
            // Audio context not allowed or unsupported; safe fallback
        }
    };

    // COE Kit Counter Location Text Constants & Formatter (Highlighted Badge Style)
    const COE_KIT_MESSAGE_TEMPLATE = "Kindly collect the COE Kit from {counter}";
    const COE_KIT_FALLBACK_TEXT = "Kindly collect the COE Kit from the Help Desk";

    const buildCoeKitHtml = (counter, subtext) => {
        let c = (counter && typeof counter === 'string') ? counter.trim() : '';
        if (!c || c.toLowerCase() === 'null' || c.toLowerCase() === 'undefined' || c === '—' || c === '-') {
            c = 'Help Desk';
        }
        if (!c.toLowerCase().includes('counter') && !c.toLowerCase().includes('help desk')) {
            c = c + ' Counter';
        }
        const sub = (subtext && typeof subtext === 'string') ? subtext.trim() : '';
        const subHtml = sub !== '' ? `<div class="coe-kit-subtext text-muted small">${escapeHtml(sub)}</div>` : '';

        return `
            <div class="coe-kit-notice-box my-2 text-center">
                <div class="coe-kit-notice-title small text-secondary fw-semibold mb-1">Kindly collect the COE Kit from</div>
                <div class="coe-kit-badge-wrap mb-1">
                    <span class="badge coe-kit-counter-pill">
                        <i class="bi bi-box-seam me-1.5"></i>${escapeHtml(c)}
                    </span>
                </div>
                ${subHtml}
            </div>
        `;
    };

    // Native Mobile Digit / Register Id Sanitizer & Type Auto-Detector
    const sanitizeAndCount = () => {
        if (!mobileInput) return;
        const rawVal = mobileInput.value;
        const trimmed = rawVal.trim();
        const prefixBox = document.getElementById('phonePrefixBox');
        const inputWrap = mobileInput.closest('.phone-input-wrap') || mobileInput.parentElement;

        // Check if input is purely numeric (after stripping spaces, dashes, parens, and leading +91 or +)
        let testStr = trimmed.replace(/[\s\-()]/g, '');
        if (testStr.startsWith('+91')) {
            testStr = testStr.slice(3);
        } else if (testStr.startsWith('+')) {
            testStr = testStr.slice(1);
        }
        const isPureNumeric = testStr.length > 0 && /^\d+$/.test(testStr);

        if (trimmed === '') {
            // STATE 1: EMPTY FIELD
            // Hide +91 prefix and counter; input starts at left edge with clean placeholder
            if (prefixBox) prefixBox.style.display = 'none';
            if (digitCounter) {
                digitCounter.style.visibility = 'hidden';
                digitCounter.textContent = '0 / 10 digits';
                digitCounter.className = 'digit-counter-badge';
            }
            if (inputWrap) inputWrap.classList.remove('has-prefix');
            mobileInput.classList.remove('has-prefix', 'user-id-mode');
            mobileInput.style.paddingLeft = '16px';
            mobileInput.style.letterSpacing = '0';
        } else if (isPureNumeric) {
            // STATE 2: NUMERIC MOBILE (digits only)
            // Show +91 prefix & counter; letter-spacing: 0.5px; padding-left accommodates prefix
            if (prefixBox) prefixBox.style.display = 'flex';
            if (digitCounter) {
                digitCounter.style.visibility = 'visible';
            }
            if (inputWrap) inputWrap.classList.add('has-prefix');
            mobileInput.classList.add('has-prefix');
            mobileInput.classList.remove('user-id-mode');
            mobileInput.style.paddingLeft = '60px';
            mobileInput.style.letterSpacing = '0.5px';

            let digits = testStr;
            if (digits.length === 12 && digits.startsWith('91')) {
                digits = digits.slice(2);
            } else if (digits.length === 11 && digits.startsWith('0')) {
                digits = digits.slice(1);
            }
            if (digits.length > 10) {
                digits = digits.slice(0, 10);
            }

            // Only overwrite if text was all digits / formatting
            if (rawVal !== '' && /^[0-9+\s()-]+$/.test(mobileInput.value) && mobileInput.value !== digits) {
                mobileInput.value = digits;
            }

            if (digitCounter) {
                digitCounter.textContent = `${digits.length} / 10 digits`;
                if (digits.length === 10) {
                    digitCounter.className = 'digit-counter-badge is-valid';
                } else {
                    digitCounter.className = 'digit-counter-badge';
                }
            }
        } else {
            // STATE 3: REGISTER ID (contains any letter, @, _, or symbol)
            // Hide +91 prefix & counter completely; letter-spacing: 0; padding-left: 16px
            if (prefixBox) prefixBox.style.display = 'none';
            if (digitCounter) {
                digitCounter.style.visibility = 'hidden';
            }
            if (inputWrap) inputWrap.classList.remove('has-prefix');
            mobileInput.classList.remove('has-prefix');
            mobileInput.classList.add('user-id-mode');
            mobileInput.style.paddingLeft = '16px';
            mobileInput.style.letterSpacing = '0';
        }
    };

    if (mobileInput) {
        mobileInput.addEventListener('input', sanitizeAndCount);
        mobileInput.addEventListener('keyup', sanitizeAndCount);
        mobileInput.addEventListener('change', sanitizeAndCount);
        mobileInput.addEventListener('paste', () => setTimeout(sanitizeAndCount, 10));

        // Auto-select text on focus and scroll into view smoothly when virtual keyboard opens
        mobileInput.addEventListener('focus', () => {
            mobileInput.select();
            setTimeout(() => {
                mobileInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 250);
        });

        // Initialize state on load
        sanitizeAndCount();
    }

    // Form Submission (Gate Terminal AJAX)
    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const rawVal = mobileInput ? mobileInput.value.trim() : '';
            if (!rawVal) {
                playAudioFeedback('denied');
                renderResult({
                    status: 'invalid_input',
                    message: 'Please enter your mobile number or Register Id.'
                });
                if (mobileInput) mobileInput.focus();
                return;
            }

            let testStr = rawVal.replace(/[\s\-()]/g, '');
            if (testStr.startsWith('+91')) testStr = testStr.slice(3);
            else if (testStr.startsWith('+')) testStr = testStr.slice(1);
            const isPureNumeric = testStr.length > 0 && /^\d+$/.test(testStr);

            if (isPureNumeric) {
                const digits = testStr;
                if (digits.length < 10) {
                    playAudioFeedback('denied');
                    renderResult({
                        status: 'invalid_input',
                        message: 'Please enter a valid 10-digit mobile number or Register Id.'
                    });
                    if (mobileInput) mobileInput.focus();
                    return;
                }
            }

            // Set loading state on button to prevent duplicate submission
            const activeDay = dayInput ? parseInt(dayInput.value, 10) : 1;
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-2 text-white" role="status" aria-hidden="true"></span> Validating Day ${activeDay}...`;
            }

            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ 
                        identifier: rawVal,
                        mobile: rawVal,
                        day: activeDay
                    })
                });

                if (!response.ok && response.status !== 503) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();
                renderResult(data);

            } catch (err) {
                renderResult({
                    status: 'error',
                    message: 'Network error, please try again.'
                });
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    const curDay = dayInput ? parseInt(dayInput.value, 10) : 1;
                    submitBtn.innerHTML = `<i class="bi bi-shield-check me-2"></i> Verify &amp; Check In (<span id="submitDayLabel">Day ${curDay}</span>)`;
                }
            }
        });
    }

    // Form Submission (COE Kit Terminal AJAX)
    const giftForm = document.getElementById('giftClaimForm');
    if (giftForm) {
        giftForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const rawVal = mobileInput ? mobileInput.value.trim() : '';
            if (!rawVal) {
                playAudioFeedback('denied');
                renderResult({
                    status: 'invalid_input',
                    message: 'Please enter registered mobile number or Register Id.',
                    isGift: true
                });
                if (mobileInput) mobileInput.focus();
                return;
            }

            let testStr = rawVal.replace(/[\s\-()]/g, '');
            if (testStr.startsWith('+91')) testStr = testStr.slice(3);
            else if (testStr.startsWith('+')) testStr = testStr.slice(1);
            const isPureNumeric = testStr.length > 0 && /^\d+$/.test(testStr);

            if (isPureNumeric && testStr.length < 10) {
                playAudioFeedback('denied');
                renderResult({
                    status: 'invalid_input',
                    message: 'Please enter a valid 10-digit mobile number or Register Id.',
                    isGift: true
                });
                if (mobileInput) mobileInput.focus();
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-2 text-white" role="status" aria-hidden="true"></span> Verifying COE Kit...`;
            }

            try {
                const response = await fetch('gift.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ 
                        identifier: rawVal,
                        mobile: rawVal
                    })
                });

                const data = await response.json();
                data.isGift = true;
                renderResult(data);

            } catch (err) {
                renderResult({
                    status: 'error',
                    message: 'Network error, please try again.',
                    isGift: true
                });
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = `<i class="bi bi-box-seam me-2"></i> Verify &amp; Handover COE Kit`;
                }
            }
        });
    }

    // Render Status Feedback in Crisp Hybrid Card Result Container
    const renderResult = (data) => {
        if (!resultContainer) return;

        let html = '';
        const dayNum = data.day || (dayInput ? parseInt(dayInput.value, 10) : 1);
        const isGiftTerminal = Boolean(data.isGift || giftForm);

        if (data.status === 'success') {
            playAudioFeedback('success');
            const displayTime = data.formatted_time || (data.checked_in_at ? formatIST(data.checked_in_at) : (data.gift_collected_at ? formatIST(data.gift_collected_at) : formatIST(new Date())));
            const fincertText = (data.fincert && String(data.fincert).trim() !== '') ? String(data.fincert).trim() : '';
            const fincertBlock = fincertText !== ''
                ? `<div class="result-id-line fw-bold text-dark my-1" style="font-size: 0.95rem; line-height: 1.3;">Your Id no. is <span class="text-primary text-break">${escapeHtml(fincertText)}</span></div>`
                : '';
            const phoneLine = (data.mobile && String(data.mobile).trim() !== '')
                ? `<i class="bi bi-telephone me-1 text-primary"></i>+91 ${escapeHtml(data.mobile)}`
                : `<i class="bi bi-person-badge me-1 text-primary"></i>Register Id: ${escapeHtml(data.user_id || '')}`;

            if (isGiftTerminal) {
                const counterName = data.counter_name || 'Assigned Regional Counter';
                html = `
                    <div class="result-box status-success shadow-sm mb-3">
                        <div class="status-icon-circle">
                            <i class="bi bi-check-circle-fill"></i>
                        </div>
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill mb-2 fw-bold text-uppercase" style="letter-spacing: 0.5px;">COE KIT APPROVED</span>
                        <h4 class="fw-bold mb-1" style="color: #0f1c30;">${escapeHtml(data.name || 'Visitor')}</h4>
                        <p class="fs-6 fw-bold mb-2 text-success">${escapeHtml(data.message || 'COE Kit Handover Approved!')}</p>
                        ${fincertBlock}
                        <div class="p-2 bg-light rounded-3 border d-inline-block small text-secondary mt-2">
                            <i class="bi bi-box-seam me-1 text-primary"></i> Counter: <strong class="text-dark">${escapeHtml(counterName)}</strong>
                            <span class="mx-2 text-muted">|</span>
                            <i class="bi bi-clock me-1 text-success"></i> Handed At: <strong class="text-dark">${escapeHtml(displayTime)}</strong>
                            <span class="mx-2 text-muted">|</span>
                            ${phoneLine}
                        </div>
                    </div>
                `;
            } else {
                const counterRegion = data.counter_name || data.coe_kit_text || data.region || '';
                const coeKitLine = data.coe_kit_html || buildCoeKitHtml(counterRegion, data.counter_subtext || '');

                html = `
                    <div class="result-box status-success shadow-sm mb-2">
                        <div class="status-icon-circle">
                            <i class="bi bi-check-circle-fill"></i>
                        </div>
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase" style="letter-spacing: 0.4px;">DAY ${escapeHtml(dayNum)} ENTRY APPROVED</span>
                        <h5 class="fw-bold mb-1" style="color: #0f1c30;">${escapeHtml(data.name || 'Visitor')}</h5>
                        <p class="small fw-bold mb-1 text-success">Welcome to Day ${escapeHtml(dayNum)}, ${escapeHtml(data.name || 'Visitor')}!</p>
                        ${fincertBlock}
                        ${coeKitLine}
                        <div class="result-meta-box">
                            <div class="result-meta-row-label"><i class="bi bi-clock me-1 text-success"></i>Day ${escapeHtml(dayNum)} Checked In</div>
                            <div class="result-meta-row-datetime">${escapeHtml(displayTime)}</div>
                            <div class="result-meta-row-phone">${phoneLine}</div>
                        </div>
                    </div>
                `;
            }

            // Clear input for rapid next attendee check-in
            setTimeout(() => {
                if (mobileInput) {
                    mobileInput.value = '';
                    sanitizeAndCount();
                    mobileInput.focus();
                }
            }, 3000);

        } else if (data.status === 'wrong_counter') {
            playAudioFeedback('duplicate');
            const targetCounter = data.counter_name || (data.visitor_region ? `${data.visitor_region} Counter` : 'Help Desk');
            const sanitizedMsg = data.message || `Your COE Kit counter is ${targetCounter}. Please go to the ${targetCounter}.`;

            html = `
                <div class="result-box status-duplicate shadow-sm mb-3" style="background:#fffbeb;border:1.5px solid #fcd34d;">
                    <div class="status-icon-circle" style="background:#fef3c7;color:#d97706;">
                        <i class="bi bi-geo-alt-fill"></i>
                    </div>
                    <span class="badge bg-warning text-dark border border-warning-subtle px-3 py-1 rounded-pill mb-2 fw-bold text-uppercase" style="letter-spacing: 0.5px;">WRONG COUNTER</span>
                    <h4 class="fw-bold mb-1" style="color: #0f1c30;">${escapeHtml(data.name || 'Visitor')}</h4>
                    <p class="fs-6 fw-bold text-warning-emphasis mb-2">${sanitizedMsg}</p>
                    <div class="alert alert-warning border-warning-subtle text-warning-emphasis py-2 px-3 mb-0 small text-start">
                        <i class="bi bi-signpost-split-fill me-1 text-warning"></i>
                        Please direct this attendee to <strong>${escapeHtml(targetCounter)}</strong>.
                    </div>
                </div>
            `;

        } else if (data.status === 'not_entered') {
            playAudioFeedback('duplicate');
            const fincertText = (data.fincert && String(data.fincert).trim() !== '') ? String(data.fincert).trim() : '';
            const fincertBlock = fincertText !== ''
                ? `<div class="result-id-line fw-bold text-dark my-1" style="font-size: 0.9rem; line-height: 1.3;">Your Id no. is <span class="text-primary text-break">${escapeHtml(fincertText)}</span></div>`
                : '';
            const notEnteredMsg = data.message || `${escapeHtml(data.name || 'Visitor')}, your gate entry is not done yet. Please complete entry at the gate first, then come back for your COE Kit.`;

            html = `
                <div class="result-box status-duplicate shadow-sm mb-3" style="background:#fffbeb;border:1.5px solid #fcd34d;">
                    <div class="status-icon-circle" style="background:#fef3c7;color:#d97706;">
                        <i class="bi bi-door-closed-fill"></i>
                    </div>
                    <span class="badge bg-warning text-dark border border-warning-subtle px-3 py-1 rounded-pill mb-2 fw-bold text-uppercase" style="letter-spacing: 0.5px;">GATE ENTRY REQUIRED</span>
                    <h4 class="fw-bold mb-1" style="color: #0f1c30;">${escapeHtml(data.name || 'Visitor')}</h4>
                    <p class="fs-6 fw-bold text-warning-emphasis mb-2">${escapeHtml(notEnteredMsg)}</p>
                    ${fincertBlock}
                    <div class="alert alert-warning border-warning-subtle text-warning-emphasis py-2 px-3 mb-0 small text-start">
                        <i class="bi bi-info-circle-fill me-1 text-warning"></i>
                        Please complete entry at the gate terminal first, then return to claim your COE Kit.
                    </div>
                </div>
            `;

        } else if (data.status === 'duplicate') {
            playAudioFeedback('duplicate');
            const dupTime = data.formatted_time || (data.checked_in_at ? formatIST(data.checked_in_at) : (data.gift_collected_at ? formatIST(data.gift_collected_at) : 'Recorded'));
            const fincertText = (data.fincert && String(data.fincert).trim() !== '') ? String(data.fincert).trim() : '';
            const fincertBlock = fincertText !== ''
                ? `<div class="result-id-line fw-bold text-dark my-1" style="font-size: 0.9rem; line-height: 1.3;">Your Id no. is <span class="text-primary text-break">${escapeHtml(fincertText)}</span></div>`
                : '';
            const phoneLine = (data.mobile && String(data.mobile).trim() !== '')
                ? `<i class="bi bi-telephone me-1 text-primary"></i>+91 ${escapeHtml(data.mobile)}`
                : `<i class="bi bi-person-badge me-1 text-primary"></i>Register Id: ${escapeHtml(data.user_id || '')}`;

            if (isGiftTerminal) {
                html = `
                    <div class="result-box status-duplicate shadow-sm mb-3">
                        <div class="status-icon-circle">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                        </div>
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-1 rounded-pill mb-2 fw-bold text-uppercase" style="letter-spacing: 0.5px;">COE KIT BLOCKED</span>
                        <h4 class="fw-bold mb-1" style="color: #0f1c30;">${escapeHtml(data.name || 'Visitor')}</h4>
                        <p class="fs-6 fw-bold text-warning-emphasis mb-2">${escapeHtml(data.message || 'Already COE Kit Ghetla Aahe!')}</p>
                        <div class="p-2 bg-light rounded-3 border d-inline-block small text-secondary">
                            <i class="bi bi-clock-history me-1 text-warning"></i> Prior Collection: <strong class="text-dark">${escapeHtml(dupTime)}</strong>
                            ${data.counter_region ? `<span class="mx-2 text-muted">|</span><i class="bi bi-geo-alt me-1 text-primary"></i> <strong class="text-dark">${escapeHtml(data.counter_region)}</strong>` : ''}
                            <span class="mx-2 text-muted">|</span>
                            ${phoneLine}
                        </div>
                    </div>
                `;
            } else {
                const counterRegion = data.counter_name || data.coe_kit_text || data.region || '';
                const coeKitLine = data.coe_kit_html || buildCoeKitHtml(counterRegion, data.counter_subtext || '');

                html = `
                    <div class="result-box status-duplicate shadow-sm mb-2">
                        <div class="status-icon-circle">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                        </div>
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase" style="letter-spacing: 0.4px;">DAY ${escapeHtml(dayNum)} ALREADY CHECKED IN</span>
                        <h5 class="fw-bold mb-1" style="color: #0f1c30;">${escapeHtml(data.name || 'Visitor')}</h5>
                        <p class="small fw-bold text-warning-emphasis mb-1.5">${escapeHtml(data.message || 'Duplicate Entry: Already checked in')}</p>
                        ${fincertBlock}
                        ${coeKitLine}
                        <div class="result-meta-box">
                            <div class="result-meta-row-label"><i class="bi bi-clock-history me-1 text-warning"></i>Prior Check-in</div>
                            <div class="result-meta-row-datetime">${escapeHtml(dupTime)}</div>
                            <div class="result-meta-row-phone">${phoneLine}</div>
                        </div>
                    </div>
                `;
            }

        } else if (data.status === 'auth_required') {
            playAudioFeedback('denied');
            html = `
                <div class="alert alert-danger border-danger-subtle text-danger-emphasis rounded-3 shadow-sm py-2 px-3 mb-2" role="alert">
                    <i class="bi bi-lock-fill fs-5 me-2 text-danger"></i>
                    <div>
                        <div class="fw-bold">Counter Login Required</div>
                        <div class="small mb-2">${escapeHtml(data.message || 'Please sign in to access this terminal.')}</div>
                        <a href="${escapeHtml(data.login_url || 'login.php?redirect=gift.php')}" class="btn btn-sm btn-danger rounded-pill px-3 fw-bold">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Sign In Now
                        </a>
                    </div>
                </div>
            `;

        } else if (data.status === 'multiple_matches') {
            playAudioFeedback('duplicate');
            html = `
                <div class="result-box status-duplicate shadow-sm mb-2" style="background:#fffbeb;border-color:#fde68a;">
                    <div class="status-icon-circle" style="background:#fef3c7;color:#d97706;">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase" style="letter-spacing: 0.4px;">MULTIPLE REGISTRATIONS</span>
                    <h5 class="fw-bold mb-1 text-dark" style="font-size:0.9rem;line-height:1.35;">${escapeHtml(data.message)}</h5>
                    <div class="alert alert-warning border-warning-subtle text-warning-emphasis py-1 px-2 mb-0 small" style="font-size: 0.73rem;">
                        <i class="bi bi-shield-exclamation me-1"></i> Shared mobile number detected. Please enter your unique Register Id to confirm entry.
                    </div>
                </div>
            `;

        } else if (data.status === 'not_found') {
            playAudioFeedback('denied');
            html = `
                <div class="result-box status-denied shadow-sm mb-2">
                    <div class="status-icon-circle">
                        <i class="bi bi-slash-circle-fill"></i>
                    </div>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase" style="letter-spacing: 0.4px;">ACCESS REJECTED</span>
                    <h5 class="fw-bold mb-1" style="color: #0f1c30;">${escapeHtml(data.message || 'Not registered. Please check your mobile number or Register Id.')}</h5>
                    <div class="alert alert-danger bg-danger-subtle border-danger-subtle text-danger-emphasis py-1 px-2 mb-0 small" style="font-size: 0.73rem;">
                        <i class="bi bi-info-circle me-1"></i> Number or Register Id not registered in ${isGiftTerminal ? 'COE Kit distribution list' : 'allowed visitor list'}.
                    </div>
                </div>
            `;

        } else {
            // General validation notice / Error notice
            html = `
                <div class="alert alert-warning border-warning-subtle text-warning-emphasis d-flex align-items-center rounded-3 shadow-sm py-1.5 px-2 mb-2" role="alert">
                    <i class="bi bi-exclamation-circle-fill fs-6 me-2 text-warning"></i>
                    <div>
                        <div class="fw-bold small">Notice</div>
                        <div class="small" style="font-size: 0.75rem;">${escapeHtml(data.message || 'Invalid request')}</div>
                    </div>
                </div>
            `;
        }

        resultContainer.innerHTML = html;
    };

    const escapeHtml = (str) => {
        return String(str).replace(/[&<>"']/g, (m) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[m]));
    };
});
