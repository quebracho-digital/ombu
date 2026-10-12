// The buyer agent: given a task ("should we hire vendor X?") and a budget, it
// reads Ombú's OpenAPI, buys the evidence it needs from the market and
// decides hire / reject / ask a human, with its reasons and the tx hashes it
// spent. The LLM chooses what to buy; the budget is enforced here, before
// signing, never left to the model.
//
// Run from ombu/:
//   node --env-file=../x402_pay/scripts/.env --env-file=.ddev/.env \
//     scripts/agent.mjs "Should we hire Brevo for transactional email?" [budget USDC, default 0.05] [site]
// Env: BUYER_PRIVATE_KEY; LLM_URL (OpenAI-compatible, default minisforum's
// router), LLM_API_KEY or LLAMA_API_KEY, LLM_MODEL.
// For DDEV's certificate: NODE_EXTRA_CA_CERTS="$(mkcert -CAROOT)/rootCA.pem"
import { pay } from "../../x402_pay/scripts/x402.mjs";

const [task, budgetArg = "0.05", site = "https://ombu.ddev.site"] = process.argv.slice(2);
if (!task) throw new Error('Usage: agent.mjs "<task>" [budget USDC] [site]');
const LLM_URL = process.env.LLM_URL ?? "http://192.168.1.150:8080/v1";
const LLM_KEY = process.env.LLM_API_KEY ?? process.env.LLAMA_API_KEY;
const MODEL = process.env.LLM_MODEL ?? "unsloth/Qwen3.6-35B-A3B-GGUF:UD-Q4_K_XL";
const MAX_TURNS = 12;
// What a tool answer may put in the model's context.
const MAX_RESULT = 8000;
// A page's markdown is cut, never its links: they lead to the terms.
const clip = (body) => {
  try {
    const page = JSON.parse(body);
    if (typeof page.markdown === "string") return JSON.stringify({ ...page, markdown: page.markdown.slice(0, MAX_RESULT) });
  }
  catch {}
  return body.slice(0, MAX_RESULT);
};
const budget = BigInt(Math.round(Number(budgetArg) * 1e6));
const usdc = (atomic) => (Number(atomic) / 1e6).toString();
const log = (...a) => console.error(...a);

// The market's tools, from its OpenAPI: search, and one per paid service.
const spec = await (await fetch(`${site}/openapi.json`)).json();
const services = {};
for (const [path, ops] of Object.entries(spec.paths)) {
  const op = ops.post;
  if (!op?.["x-x402"]) continue;
  const name = `buy_${path.match(/\/(\d+)\//)[1]}`;
  services[name] = { url: spec.servers[0].url + path, op };
}
const tools = [
  {
    name: "search_market",
    description: "Search the market's listings (free). Returns titles, prices, networks and endpoints.",
    parameters: { type: "object", properties: { search: { type: "string" } }, required: ["search"] },
  },
  ...Object.entries(services).map(([name, { op }]) => ({
    name,
    description: `${op.summary}. Costs ${op["x-x402"].price} USDC from your budget, paid on Arc. ${op.description}`.slice(0, 1000),
    parameters: op.requestBody.content["application/json"].schema,
  })),
  {
    name: "decide",
    description: "Your final answer. Call it once, when the evidence is enough or the budget is gone.",
    parameters: {
      type: "object",
      required: ["verdict", "reasons"],
      properties: {
        verdict: { type: "string", enum: ["hire", "reject", "ask_human"] },
        reasons: {
          type: "array",
          items: {
            type: "object",
            required: ["reason", "purchase"],
            properties: {
              reason: { type: "string" },
              purchase: { type: "integer", description: "The number of the purchase this reason rests on; 0 if none." },
            },
          },
        },
      },
    },
  },
].map((f) => ({ type: "function", function: f }));

const spent = [];
const left = () => budget - spent.reduce((sum, s) => sum + BigInt(s.amount), 0n);

async function run(name, args) {
  if (name === "search_market") {
    const page = await (await fetch(`${site}/market.json?limit=10&search=${encodeURIComponent(args.search ?? "")}`)).json();
    return page.items.map((i) => ({
      title: i.metadata.description,
      provider: i.metadata.provider?.name,
      price_usdc: i.accepts[0] ? usdc(i.accepts[0].amount) : null,
      network: i.accepts[0]?.network,
      resource: i.resource,
    }));
  }
  const service = services[name];
  if (!service) return { error: `No tool ${name}.` };
  log(`→ ${service.op.summary} ${JSON.stringify(args)}`);
  try {
    const paid = await pay(service.url, {
      input: args,
      approve: (req) => {
        if (BigInt(req.amount) > left()) {
          throw new Error(`Over budget: costs ${usdc(req.amount)} USDC, ${usdc(left())} left. Not paid.`);
        }
      },
    });
    if (paid.receipt?.success) {
      spent.push({ service: service.op.summary, input: args, amount: paid.req.amount, tx: paid.receipt.transaction });
      log(`  paid ${usdc(paid.req.amount)} USDC, tx ${paid.receipt.transaction}`);
    }
    else {
      log(`  HTTP ${paid.status}, not charged`);
    }
    return { status: paid.status, charged: Boolean(paid.receipt?.success), ...(paid.receipt?.success ? { purchase: spent.length } : {}), budget_left_usdc: usdc(left()), result: clip(paid.body) };
  }
  catch (e) {
    log(`  refused: ${e.message}`);
    return { error: e.message, budget_left_usdc: usdc(left()) };
  }
}

const messages = [
  {
    role: "system",
    content: `You are a procurement agent for a small business. You answer the task with a decision: hire, reject, or ask_human.
You can buy evidence from a market. Every purchase spends real USDC from a budget of ${usdc(budget)} USDC: buy only what can change the decision.
For a vendor, its terms of service and privacy policy are the usual evidence: pass their real URLs to the terms analysis. Do not guess URLs: buy the vendor's home page as markdown and follow its links (usually in the footer). Fact-check a vendor's claims only when the decision depends on them.
Never invent findings: every reason must rest on what a purchase returned, and cites that purchase's number. What you know without buying does not count. If evidence is missing or the budget runs out, say so and ask_human.
Finish by calling decide.`,
  },
  { role: "user", content: task },
];

// One chat turn; the router may be busy swapping models: two more tries.
async function chat(last) {
  for (let attempt = 1; ; attempt++) {
    const response = await fetch(`${LLM_URL}/chat/completions`, {
      method: "POST",
      headers: { "Content-Type": "application/json", ...(LLM_KEY ? { Authorization: `Bearer ${LLM_KEY}` } : {}) },
      body: JSON.stringify({ model: MODEL, messages, tools: last ? tools.filter((t) => t.function.name === "decide") : tools, tool_choice: last ? "required" : "auto", temperature: 0, chat_template_kwargs: { enable_thinking: false } }),
    });
    if (response.ok) return (await response.json()).choices[0].message;
    const error = `LLM: HTTP ${response.status} ${(await response.text()).slice(0, 300)}`;
    if (attempt === 3) throw new Error(error);
    log(`  ${error}; retrying`);
    await new Promise((r) => setTimeout(r, 10000 * attempt));
  }
}

let decision;
try {
  for (let turn = 0; turn < MAX_TURNS && !decision; turn++) {
    // The last turn can only decide.
    const message = await chat(turn === MAX_TURNS - 1);
    messages.push(message);
    if (!message.tool_calls?.length) {
      messages.push({ role: "user", content: "Call a tool: buy evidence, or decide." });
      continue;
    }
    for (const call of message.tool_calls) {
      let args;
      try {
        args = JSON.parse(call.function.arguments || "{}");
      }
      catch {
        args = {};
      }
      if (call.function.name === "decide") {
        decision = args;
        break;
      }
      messages.push({ role: "tool", tool_call_id: call.id, content: JSON.stringify(await run(call.function.name, args)) });
    }
  }
}
catch (e) {
  // What was spent is reported anyway.
  decision = { verdict: "ask_human", reasons: [{ reason: `The run stopped: ${e.message}`, purchase: 0 }] };
}

// The report: the verdict is the model's, the money is ours.
const report = {
  task,
  verdict: decision?.verdict ?? "ask_human",
  // A reason that cites no purchase is the model's own knowledge: flagged.
  reasons: (decision?.reasons ?? [{ reason: `No decision within ${MAX_TURNS} turns.`, purchase: 0 }]).map((r) => {
    r = typeof r === "string" ? { reason: r, purchase: 0 } : r;
    const n = Number(r.purchase ?? 0);
    return n >= 1 && n <= spent.length ? r : { ...r, unsupported: true };
  }),
  budget_usdc: usdc(budget),
  spent_usdc: usdc(budget - left()),
  purchases: spent.map((s, i) => ({ number: i + 1, ...s, amount: usdc(s.amount), tx: `https://testnet.arcscan.app/tx/${s.tx}` })),
};
console.log(JSON.stringify(report, null, 2));
