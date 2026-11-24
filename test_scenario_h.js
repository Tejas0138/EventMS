const { BASE_URL, runDb, performSingleCheckin } = require('./scenarios');
const { CookieJar } = require('./helpers');

async function testH() {
    console.log('--- Testing Scenario H ---');
    runDb('reset_all');

    // Test 1: Tampered day in POST request
    // Day in DB is Day 1 (today is 2026-11-19). Client sends day=2 or serverDay=2
    const jar = new CookieJar();
    const getRes = await fetch(`${BASE_URL}/gate_checkin.php`);
    jar.parse(getRes);
    const html = await getRes.text();
    const match = html.match(/name="csrf_token"\s+value="([^"]+)"/i);
    const csrf = match ? match[1] : '';

    const postTampered = await fetch(`${BASE_URL}/gate_checkin.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Cookie': jar.header(),
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        },
        body: new URLSearchParams({
            identifier: 'LOADTEST_001',
            csrf_token: csrf,
            day: '2',
            serverDay: '2'
        }).toString()
    });
    const tamperedJson = await postTampered.json();
    console.log('1. Tampered Day POST: client sent day=2, server responded with day =', tamperedJson.day, tamperedJson.message);

    // Verify DB
    const dbCounts = runDb('get_counts');
    console.log('   DB Day 1 Checkins =', dbCounts.d1_checkins, ', DB Day 2 Checkins =', dbCounts.d2_checkins);

    // Test 2: Date is not Day 1 or Day 2
    runDb('set_event_dates', '2026-11-10', '2026-11-11', '1');
    const getClosed = await fetch(`${BASE_URL}/gate_checkin.php`);
    const closedHtml = await getClosed.text();
    const showsNotOpenCard = closedHtml.includes('Entry is not open today.');
    const formPresent = closedHtml.includes('id="selfCheckInForm"');
    console.log('2. Date not Day 1 or 2: Shows "Entry is not open today." card =', showsNotOpenCard, ', Form rendered =', formPresent);

    // Test 3: Switch closed
    runDb('set_event_dates', '2026-11-19', '2026-11-20', '0');
    const getSwitchClosed = await fetch(`${BASE_URL}/gate_checkin.php`);
    const switchClosedHtml = await getSwitchClosed.text();
    const showsClosedCard = switchClosedHtml.includes('Entry is closed.') || switchClosedHtml.includes('Entry is not open');
    console.log('3. Switch closed: Shows closed card =', showsClosedCard);

    // Restore Day 1
    runDb('set_event_dates', '2026-11-19', '2026-11-20', '1');
    console.log('Settings restored.');
}

testH();
