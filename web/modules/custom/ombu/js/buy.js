/**
 * @file
 * People pay a listing from the browser: the same x402 call an agent makes,
 * signed by their wallet (one EIP-3009 signature, no gas; on Solana, one
 * transaction the facilitator pays the fees of), then the result.
 */
((Drupal, once) => {
  const decode = (b64) => JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(b64), (c) => c.charCodeAt(0))));
  const encode = (value) => btoa(String.fromCharCode(...new TextEncoder().encode(JSON.stringify(value))));
  const hex = (n) => `0x${Number(n).toString(16)}`;

  // Arc's chain, added to the wallet if it does not know it yet.
  const CHAINS = {
    5042: { chainName: 'Arc', rpcUrls: ['https://rpc.mainnet.arc.io'], blockExplorerUrls: ['https://explorer.arc.io'] },
    5042002: { chainName: 'Arc testnet', rpcUrls: ['https://rpc.testnet.arc.network'], blockExplorerUrls: ['https://testnet.arcscan.app'] },
  };
  const EXPLORER = { 5042: 'https://explorer.arc.io/tx/', 5042002: 'https://testnet.arcscan.app/tx/' };
  // Wallet Standard's names for Solana's clusters, by genesis hash (CAIP-2).
  // cspell:disable-next-line
  const CLUSTERS = { '5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp': 'mainnet', EtWTRABZaYq6iMfeYKouRu166VU2xqa1: 'devnet' };

  async function useChain(ethereum, chainId) {
    try {
      await ethereum.request({ method: 'wallet_switchEthereumChain', params: [{ chainId: hex(chainId) }] });
    }
    catch (e) {
      // 4902: the wallet does not know the chain.
      if (e.code !== 4902 || !CHAINS[chainId]) {
        throw e;
      }
      await ethereum.request({ method: 'wallet_addEthereumChain', params: [{ chainId: hex(chainId), nativeCurrency: { name: 'USDC', symbol: 'USDC', decimals: 18 }, ...CHAINS[chainId] }] });
    }
  }

  // EVM: an EIP-3009 authorization for exactly that, to the seller.
  async function evm(req, chainId, status) {
    const { ethereum } = window;
    if (!ethereum) {
      throw new Error(Drupal.t('No wallet in this browser: install MetaMask.'));
    }
    status(Drupal.t('Sign the payment in your wallet…'));
    const [from] = await ethereum.request({ method: 'eth_requestAccounts' });
    await useChain(ethereum, chainId);
    const authorization = {
      from,
      to: req.payTo,
      value: String(req.amount),
      validAfter: '0',
      validBefore: String(Math.floor(Date.now() / 1000) + req.maxTimeoutSeconds),
      nonce: `0x${Array.from(crypto.getRandomValues(new Uint8Array(32)), (b) => b.toString(16).padStart(2, '0')).join('')}`,
    };
    const signature = await ethereum.request({
      method: 'eth_signTypedData_v4',
      params: [from, JSON.stringify({
        types: {
          EIP712Domain: [
            { name: 'name', type: 'string' }, { name: 'version', type: 'string' },
            { name: 'chainId', type: 'uint256' }, { name: 'verifyingContract', type: 'address' },
          ],
          TransferWithAuthorization: [
            { name: 'from', type: 'address' }, { name: 'to', type: 'address' }, { name: 'value', type: 'uint256' },
            { name: 'validAfter', type: 'uint256' }, { name: 'validBefore', type: 'uint256' }, { name: 'nonce', type: 'bytes32' },
          ],
        },
        domain: { name: req.extra.name, version: req.extra.version, chainId, verifyingContract: req.asset },
        primaryType: 'TransferWithAuthorization',
        message: authorization,
      })],
    });
    return { accepted: { ...req, extra: { ...req.extra, assetTransferMethod: 'eip3009' } }, payload: { signature, authorization } };
  }

  // The browser's Solana wallets (Wallet Standard): those loaded answer
  // app-ready at once; the first that signs transactions on the cluster.
  function solanaWallet(chain) {
    const wallets = [];
    const api = { register: (...found) => { wallets.push(...found); return () => {}; } };
    const late = ({ detail }) => detail(api);
    window.addEventListener('wallet-standard:register-wallet', late);
    window.dispatchEvent(new CustomEvent('wallet-standard:app-ready', { detail: api }));
    window.removeEventListener('wallet-standard:register-wallet', late);
    return wallets.find((w) => w.chains.includes(chain) && w.features['standard:connect'] && w.features['solana:signTransaction']);
  }

  // Solana: the site builds the transfer, the wallet signs it, the
  // facilitator adds its signature as fee payer when it settles.
  async function solana(req, cluster, builder, status) {
    const chain = `solana:${cluster}`;
    const wallet = solanaWallet(chain);
    if (!wallet) {
      throw new Error(Drupal.t('No Solana wallet in this browser: install Phantom, Solflare or Backpack.'));
    }
    status(Drupal.t('Sign the payment in your wallet…'));
    const { accounts } = await wallet.features['standard:connect'].connect();
    const account = accounts.find((a) => a.chains.includes(chain)) ?? accounts[0];
    const built = await fetch(builder, { method: 'POST', body: JSON.stringify({ accepted: req, buyer: account.address }), headers: { 'Content-Type': 'application/json' } });
    const { transaction, error } = await built.json();
    if (!transaction) {
      throw new Error(error);
    }
    const [{ signedTransaction }] = await wallet.features['solana:signTransaction'].signTransaction({
      account, chain, transaction: Uint8Array.from(atob(transaction), (c) => c.charCodeAt(0)),
    });
    return { accepted: req, payload: { transaction: btoa(String.fromCharCode(...signedTransaction)) } };
  }

  async function buy(form, input, status) {
    const call = (headers = {}) => fetch(form.dataset.ombuBuy, { method: 'POST', body: input, headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...headers } });

    // 1. The 402 says what to pay, to whom, on which chain.
    const first = await call();
    if (first.status !== 402) {
      throw new Error(`${first.status}: ${(await first.text()).slice(0, 300)}`);
    }
    const required = decode(first.headers.get('PAYMENT-REQUIRED'));
    const req = required.accepts[0];
    const [family, reference] = req.network.split(':');

    // 2. One signature: a USDC transfer of exactly that, to the seller.
    const { accepted, payload } = family === 'solana'
      ? await solana(req, CLUSTERS[reference], form.dataset.ombuSolana, status)
      : await evm(req, Number(reference), status);

    // 3. The same call, paid. Settled only if the seller answers.
    status(Drupal.t('Paid: waiting for the seller…'));
    const paid = await call({ 'PAYMENT-SIGNATURE': encode({ x402Version: 2, resource: required.resource, accepted, payload }) });
    const receipt = paid.headers.get('PAYMENT-RESPONSE');
    const tx = receipt ? decode(receipt).transaction : null;
    const link = family === 'solana'
      ? `https://explorer.solana.com/tx/${tx}${CLUSTERS[reference] === 'devnet' ? '?cluster=devnet' : ''}`
      : EXPLORER[reference] + tx;
    return { status: paid.status, body: await paid.text(), tx, link };
  }

  Drupal.behaviors.ombuBuy = {
    attach(context) {
      once('ombu-buy', '[data-ombu-buy]', context).forEach((form) => {
        const output = form.querySelector('.ombu-buy__result');
        const status = (text) => { output.textContent = text; };
        form.addEventListener('submit', async(event) => {
          event.preventDefault();
          const button = form.querySelector('button');
          button.disabled = true;
          try {
            const input = form.querySelector('textarea').value;
            JSON.parse(input);
            const result = await buy(form, input, status);
            let body = result.body;
            try {
              body = JSON.stringify(JSON.parse(body), null, 2);
            }
            catch (e) {
              // Not JSON: shown as it came.
            }
            output.textContent = body;
            if (result.tx) {
              const link = document.createElement('a');
              link.href = result.link;
              link.textContent = Drupal.t('Paid: see the transaction');
              output.before(link);
              // Reviewing it is one step away: the purchase is filled in.
              const field = document.querySelector('[name="field_tx_hash[0][value]"]');
              if (field) {
                field.value = result.tx;
              }
            }
            else {
              output.before(Drupal.t('Not charged (HTTP @status).', { '@status': result.status }));
            }
          }
          catch (e) {
            status(e.message);
          }
          button.disabled = false;
        });
      });
    },
  };
})(Drupal, once);
