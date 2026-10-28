import { useEffect, useState } from 'react'
import { api } from '../api.js'
import { useApp } from '../App.jsx'

function useForm(initial) {
  const [v, setV] = useState(initial)
  const bind = (k, type = 'text') => ({
    name: k, type,
    ...(type === 'checkbox' ? { checked: !!v[k] } : { value: v[k] ?? '' }),
    onChange: (e) => setV({ ...v, [k]: type === 'checkbox' ? e.target.checked : e.target.value }),
  })
  return [v, bind]
}

function useSubmit(fn) {
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const submit = async (e) => {
    e.preventDefault()
    setBusy(true); setErr('')
    try { await fn() } catch (x) { setErr(x.message) }
    setBusy(false)
  }
  return [submit, busy, err]
}

export function Login() {
  const { login } = useApp()
  const [v, bind] = useForm({ email: '', password: '' })
  const [submit, busy, err] = useSubmit(async () => {
    const r = await api('/auth/login', { method: 'POST', body: v })
    login(r.token, r.user)
    window.location.hash = r.user.lat ? '/' : '/impostazioni'
  })
  return (
    <div className="page narrow">
      <h1>Entra</h1>
      <p className="muted">L’account serve solo per preferiti, “casa”, segnalazioni e avvisi. La ricerca è libera.</p>
      <form className="form" onSubmit={submit}>
        <label>Email<input {...bind('email', 'email')} required autoComplete="email" /></label>
        <label>Password<input {...bind('password', 'password')} required autoComplete="current-password" /></label>
        {err && <p className="err">{err}</p>}
        <button className="btn" disabled={busy}>Entra</button>
      </form>
      <p className="small"><a href="#/password-dimenticata">Password dimenticata?</a> · <a href="#/registrati">Crea un account</a></p>
    </div>
  )
}

export function Register() {
  const { login } = useApp()
  const [v, bind] = useForm({ name: '', email: '', password: '', privacy: false })
  const [devUrl, setDevUrl] = useState('')
  const [submit, busy, err] = useSubmit(async () => {
    const r = await api('/auth/register', { method: 'POST', body: v })
    login(r.token, r.user)
    if (r.dev_verify_url) setDevUrl(r.dev_verify_url)
    else window.location.hash = '/impostazioni'
  })
  if (devUrl) {
    return (
      <div className="page narrow">
        <h1>Account creato</h1>
        <p>Ti abbiamo inviato un’email di conferma. In locale le email finiscono in <code>api/data/outbox/</code>.</p>
        <p className="small">Link di sviluppo: <a href={devUrl}>{devUrl}</a></p>
        <p><a className="btn" href="#/impostazioni">Imposta la tua “casa”</a></p>
      </div>
    )
  }
  return (
    <div className="page narrow">
      <h1>Registrati</h1>
      <form className="form" onSubmit={submit}>
        <label>Nome<input {...bind('name')} required maxLength={80} autoComplete="given-name" /></label>
        <label>Email<input {...bind('email', 'email')} required autoComplete="email" /></label>
        <label>Password (almeno 8 caratteri)<input {...bind('password', 'password')} required minLength={8} autoComplete="new-password" /></label>
        <label className="check"><input {...bind('privacy', 'checkbox')} required /> Ho letto l’<a href="#/privacy" target="_blank">informativa privacy</a></label>
        {err && <p className="err">{err}</p>}
        <button className="btn" disabled={busy}>Crea account</button>
      </form>
      <p className="small">Hai già un account? <a href="#/entra">Entra</a></p>
    </div>
  )
}

export function Forgot() {
  const [v, bind] = useForm({ email: '' })
  const [done, setDone] = useState(null)
  const [submit, busy, err] = useSubmit(async () => {
    setDone(await api('/auth/forgot', { method: 'POST', body: v }))
  })
  return (
    <div className="page narrow">
      <h1>Password dimenticata</h1>
      {done ? (
        <>
          <p>Se l’email è registrata, riceverai un link valido 60 minuti.</p>
          {done.dev_reset_url && <p className="small">Link di sviluppo: <a href={done.dev_reset_url}>{done.dev_reset_url}</a></p>}
        </>
      ) : (
        <form className="form" onSubmit={submit}>
          <label>Email<input {...bind('email', 'email')} required autoComplete="email" /></label>
          {err && <p className="err">{err}</p>}
          <button className="btn" disabled={busy}>Invia link</button>
        </form>
      )}
    </div>
  )
}

export function Reset({ token }) {
  const [v, bind] = useForm({ password: '' })
  const [ok, setOk] = useState(false)
  const [submit, busy, err] = useSubmit(async () => {
    await api('/auth/reset', { method: 'POST', body: { token, password: v.password } })
    setOk(true)
  })
  return (
    <div className="page narrow">
      <h1>Nuova password</h1>
      {ok ? <p>Password aggiornata. <a href="#/entra">Entra</a></p> : (
        <form className="form" onSubmit={submit}>
          <label>Nuova password (almeno 8 caratteri)<input {...bind('password', 'password')} required minLength={8} autoComplete="new-password" /></label>
          {err && <p className="err">{err}</p>}
          <button className="btn" disabled={busy}>Salva</button>
        </form>
      )}
    </div>
  )
}

export function Verify({ token }) {
  const { refreshUser } = useApp()
  const [state, setState] = useState('…')
  useEffect(() => {
    api('/auth/verify', { method: 'POST', body: { token } })
      .then(() => { setState('ok'); refreshUser() })
      .catch((e) => setState(e.message))
  }, [token])
  return (
    <div className="page narrow">
      <h1>Conferma email</h1>
      {state === '…' ? <p className="loading">Verifico…</p>
        : state === 'ok' ? <p>Email confermata. Ora puoi attivare gli <a href="#/avvisi">avvisi</a>.</p>
          : <p className="alert alert-err">{state}</p>}
    </div>
  )
}
