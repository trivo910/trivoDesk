// JavaScript sandbox for the flow builder "Code" node. (ESM — node/ is
// "type":"module".)
//
// DESIGN GOAL: works OUT OF THE BOX. The buyer drops the node in, writes
// code, it runs — no npm install, no .env flag. Default engine = Node's
// BUILT-IN `vm` module (always present). If `isolated-vm` happens to be
// installed, we use it automatically for hardened isolation — never
// required.
//
// Hardening that applies to BOTH engines:
//   * User code gets ONLY previousResponse / allResponses / functionArgs,
//     injected as a JSON STRING and re-parsed INSIDE the sandbox — so no
//     host object (and no host constructor chain) is reachable.
//   * No require / process / fs / network / Buffer / global is exposed.
//   * Output returns as a JSON string and is re-parsed host-side, so the
//     sandbox heap never leaks back out.
//   * A wall-clock timeout caps runaway loops.

import vm from 'node:vm';

let ivm = null;
let ivmTried = false;
async function loadIvm() {
  if (ivmTried) return ivm;
  ivmTried = true;
  try {
    const m = await import('isolated-vm');   // optional dependency
    ivm = m?.default || m;
  } catch (_) {
    ivm = null;
  }
  return ivm;
}

function buildWrapped(code) {
  // Returns a JSON string of the user's return value (or null).
  return `
    (function () {
      var __ctx = JSON.parse(__inputs);
      var previousResponse = __ctx.previousResponse;
      var allResponses     = __ctx.allResponses;
      var functionArgs     = __ctx.functionArgs;
      var __run = function () { ${code}
      };
      var __out = __run();
      return JSON.stringify(__out === undefined ? null : __out);
    })()
  `;
}

// --- Stronger engine: isolated-vm (used only if installed) ---------------
async function runWithIvm(mod, code, inputsJson, timeoutMs, memoryMb) {
  const isolate = new mod.Isolate({ memoryLimit: memoryMb });
  try {
    const context = await isolate.createContext();
    await context.global.set('__inputs', String(inputsJson));
    const script = await isolate.compileScript(buildWrapped(code));
    return await script.run(context, { timeout: timeoutMs });
  } finally {
    try { isolate.dispose(); } catch (_) { /* already gone */ }
  }
}

// --- Default engine: built-in node:vm (zero setup) -----------------------
function runWithVm(code, inputsJson, timeoutMs) {
  // Object.create(null) → context global has no inherited prototype. We
  // expose ONLY the inputs string; the vm context supplies its OWN
  // JSON/Math/Date/String/etc. intrinsics (not the host's), so there is no
  // host constructor to climb to.
  const sandbox = Object.create(null);
  sandbox.__inputs = String(inputsJson);
  const context = vm.createContext(sandbox, {
    codeGeneration: { strings: true, wasm: false },
  });
  const script = new vm.Script(buildWrapped(code), { filename: 'flow-code-node.js' });
  return script.runInContext(context, { timeout: timeoutMs, breakOnSigint: true });
}

/**
 * Run user JS. Always available (no env flag, no required install).
 * @returns {Promise<{ok:boolean, error:string|null, result:any, engine:string}>}
 */
export async function runUserCode(code, inputs = {}, opts = {}) {
  const timeoutMs = Math.min(Math.max(parseInt(opts.timeoutMs || 2000, 10) || 2000, 100), 10000);
  const memoryMb  = Math.min(Math.max(parseInt(opts.memoryMb  || 16,   10) || 16,   8),   128);

  if (typeof code !== 'string' || code.trim() === '') {
    return { ok: true, error: null, result: null, engine: 'noop' };
  }

  const inputsJson = String(JSON.stringify(inputs || {}));
  const mod = await loadIvm();
  const engine = mod ? 'isolated-vm' : 'vm';

  try {
    const raw = mod
      ? await runWithIvm(mod, code, inputsJson, timeoutMs, memoryMb)
      : runWithVm(code, inputsJson, timeoutMs);

    let result = null;
    try { result = JSON.parse(raw); } catch (_) { result = raw; }
    return { ok: true, error: null, result, engine };
  } catch (e) {
    const msg = String((e && e.message) || e || 'sandbox error');
    return { ok: false, error: msg.slice(0, 300), result: null, engine };
  }
}
