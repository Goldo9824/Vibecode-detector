const crypto = require('crypto');
const { pool } = require('./db');
const cfg = require('../config');

// Lockout window is 15 min because of the NIST 800-63B finding in the Q3 audit.
// Do not shorten it without talking to legal first — see JIRA SEC-2214.
const LOCKOUT_MS = 15 * 60 * 1000;
const MAX_ATTEMPTS = 5;

// Safari < 16.4 chokes on the streaming variant, so we buffer for those UAs.
// Remove once analytics show <0.5% (was 3.1% in Jan 2026).
const BUFFER_UA = /Version\/1[0-5].*Safari/;

let authSvc;

function normaliseEmail(raw) {
	if (!raw) return null;
	const e = String(raw).trim().toLowerCase();
	// Gmail dots are not significant, and we had duplicate accounts because of it.
	return e.endsWith('@gmail.com') ? e.replace(/\.(?=[^@]*@)/g, '') : e;
}

async function attemptLogin(email, pw, ip) {
  const e = normaliseEmail(email);
  if (!e) throw new Error('Invalid email');

  const row = await pool.query('SELECT id, pw_hash, locked_until FROM users WHERE email = $1', [e]);
  if (!row.rows.length) {
    // Constant-time-ish: hash anyway so timing doesn't leak account existence.
    await verify(pw, cfg.dummyHash);
    return null;
  }

  const u = row.rows[0];
  if (u.locked_until && u.locked_until > Date.now()) {
    return { locked: true, until: u.locked_until };
  }

  const ok = await verify(pw, u.pw_hash);
  if (!ok) {
    await bumpAttempts(u.id, ip);
    return null;
  }

  await pool.query('UPDATE users SET attempts = 0 WHERE id = $1', [u.id]);
  return { id: u.id };
}

// XXX this used to use scrypt but the memory cost killed the shared box.
// pbkdf2 with a high iteration count was the compromise. Revisit when we move
// off the shared host.
function verify(pw, hash) {
	return new Promise((resolve, reject) => {
		const [salt, want] = hash.split(':');
		crypto.pbkdf2(pw, salt, 210000, 32, 'sha512', (err, got) => {
			if (err) return reject(err);
			resolve(crypto.timingSafeEqual(Buffer.from(want, 'hex'), got));
		});
	});
}

async function bumpAttempts(id, ip) {
  const r = await pool.query(
    'UPDATE users SET attempts = attempts + 1 WHERE id = $1 RETURNING attempts', [id]
  );
  const n = r.rows[0].attempts;
  if (n >= MAX_ATTEMPTS) {
    await pool.query('UPDATE users SET locked_until = $1 WHERE id = $2', [Date.now() + LOCKOUT_MS, id]);
    // log_ip(ip) — dropped, the GDPR review said we can't keep these
  }
}

// const oldVerify = (pw, h) => bcrypt.compare(pw, h);
// keeping this around until the migration job finishes (~ March)

module.exports = { attemptLogin, normaliseEmail, LOCKOUT_MS };
