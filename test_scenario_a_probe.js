const { performSingleCheckin, runDb } = require('./scenarios');

async function testProbe() {
    console.log('Resetting DB...');
    runDb('reset_all');

    console.log('Sending 15 check-ins rapidly...');
    const results = [];
    for (let i = 1; i <= 15; i++) {
        const id = `LOADTEST_${String(i).padStart(3, '0')}`;
        const res = await performSingleCheckin(id, 0);
        results.push(res);
        console.log(`Req #${i} (${id}): HTTP ${res.status}, Status=${res.json?.status}, Msg=${res.json?.message}`);
    }

    const successful = results.filter(r => r.json?.status === 'success').length;
    const rateLimited = results.filter(r => r.json?.status === 'rate_limited').length;
    console.log(`Summary: ${successful} successful, ${rateLimited} rate limited.`);
}

testProbe();
