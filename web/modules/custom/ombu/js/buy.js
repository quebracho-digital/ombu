/**
 * @file
 * People pay a listing from the browser: the same x402 call an agent makes,
 * signed by their wallet (one EIP-3009 signature, no gas), then the result.
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

  async function buy(url, input, status) {
    const { ethereum } = window;
    if (!ethereum) {
      throw new Error(Drupal.t('No wallet in this browser: install MetaMask.'));
    }
    const call = (headers = {}) => fetch(url, { method: 'POST', body: input, headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...headers } });

    // 1. The 402 says what to pay, to whom, on which chain.
    const first = await call();
    if (first.status !== 402) {
      throw new Error(`${first.status}: ${(await first.text()).slice(0, 300)}`);
    }
    const required = decode(first.headers.get('PAYMENT-REQUIRED'));
    const req = required.accepts[0];
    const chainId = Number(req.network.split(':')[1]);

    // 2. One signature: a USDC transfer of exactly that, to the seller.
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

    // 3. The same call, paid. Settled only if the seller answers.
    status(Drupal.t('Paid: waiting for the seller…'));
    const paid = await call({
      'PAYMENT-SIGNATURE': encode({
        x402Version: 2,
        resource: required.resource,
        accepted: { ...req, extra: { ...req.extra, assetTransferMethod: 'eip3009' } },
        payload: { signature, authorization },
      }),
    });
    const receipt = paid.headers.get('PAYMENT-RESPONSE');
    return { status: paid.status, body: await paid.text(), tx: receipt ? decode(receipt).transaction : null, explorer: EXPLORER[chainId] };
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
            const result = await buy(form.dataset.ombuBuy, input, status);
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
              link.href = result.explorer + result.tx;
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
