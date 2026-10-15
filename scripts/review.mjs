// A buyer reviews what it bought: signs in with its wallet (SIWE), then
// POST /market/listing/{id}/reviews with the purchase's tx. Ombú accepts it
// only if that tx bought this listing from this wallet.
//
// Run from ombu/ (a past purchase):
//   node --env-file=../x402_pay/scripts/.env.mainnet scripts/review.mjs \
//     <listing id> <tx> <rating 1-5> "<what you found>" [site]
// Env: BUYER_PRIVATE_KEY. agent.mjs imports review() for its own purchases.
import { privateKeyToAccount } from "viem/accounts";
import { createSiweMessage } from "viem/siwe";

const sessions = {};

async function session(site) {
  if (sessions[site]) return sessions[site];
  const account = privateKeyToAccount(process.env.BUYER_PRIVATE_KEY);
  const { nonce, domain, uri } = await (await fetch(`${site}/wallet/nonce`)).json();
  const message = createSiweMessage({ domain, uri, address: account.address, statement: "Sign in as an agent.", version: "1", chainId: 5042, nonce });
  const login = await fetch(`${site}/wallet/login`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ message, signature: await account.signMessage({ message }) }),
  });
  if (!login.ok) throw new Error(`Sign-in: ${login.status} ${(await login.text()).slice(0, 200)}`);
  const cookie = login.headers.get("set-cookie").split(";")[0];
  const token = await (await fetch(`${site}/session/token`, { headers: { cookie } })).text();
  return (sessions[site] = { cookie, token });
}

export async function review(site, listing, tx, rating, body) {
  const { cookie, token } = await session(site);
  const response = await fetch(`${site}/market/listing/${listing}/reviews`, {
    method: "POST",
    headers: { "Content-Type": "application/json", cookie, "X-CSRF-Token": token },
    body: JSON.stringify({ rating, body, tx }),
  });
  return { status: response.status, ...(await response.json()) };
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const [listing, tx, rating, body, site = "https://ombu.quebracho.digital"] = process.argv.slice(2);
  if (!body) throw new Error('Usage: review.mjs <listing id> <tx> <rating 1-5> "<review>" [site]');
  console.log(JSON.stringify(await review(site, listing, tx, Number(rating), body), null, 2));
}
