using System;
using System.Collections.Generic;
using System.IO;
using System.Net;
using System.Net.NetworkInformation;
using System.Text;
using System.Threading;

/// <summary>
/// Portal cativo LOCAL em :80.
/// Probes do celular SEMPRE abrem http://{portal_ip}/ (nunca HTTPS na nuvem).
/// Liberacao grava o IP LAN em authorized.json (DnsProxy libera por IP da LAN).
/// </summary>
internal static class CaptiveHttp
{
    private static readonly string AuthPath;
    private static readonly string InfoPath;
    private static readonly string CloudPath;
    private static readonly string ClientsDir;
    private static readonly object AuthLock = new object();
    private static long _authMtime;
    private static long _infoMtime;
    private static CaptiveState _state = new CaptiveState();

    static CaptiveHttp()
    {
        string dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "WiFiDaLoja");
        Directory.CreateDirectory(dir);
        AuthPath = Path.Combine(dir, "authorized.json");
        InfoPath = Path.Combine(dir, "store-info.json");
        CloudPath = Path.Combine(dir, "cloud.json");
        ClientsDir = Path.Combine(dir, "clients");
        Directory.CreateDirectory(ClientsDir);
    }

    public static void Main()
    {
        LoadState();
        var listener = new HttpListener();
        listener.Prefixes.Add("http://+:80/");
        try
        {
            listener.Start();
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine("Captive HTTP falhou (porta 80): " + ex.Message);
            return;
        }
        Console.Error.WriteLine("Captive HTTP local em :80");
        while (true)
        {
            HttpListenerContext ctx;
            try
            {
                ctx = listener.GetContext();
            }
            catch
            {
                continue;
            }
            ThreadPool.QueueUserWorkItem(_ => Handle(ctx));
        }
    }

    private sealed class CaptiveState
    {
        public string PortalUrl = "";
        public string PortalIp = "192.168.137.1";
        public string StoreName = "Wi-Fi da loja";
        public string StatusTemplate = "Estou na {loja} agora! Codigo {codigo}";
        public string Ssid = "";
        public string PortalTitle = "Wi-Fi da loja";
        public string PortalSubtitle = "";
        public string ApprovalMode = "instant";
        public int MaxClients = 8;
        public int SessionHours = 2;
        public bool ServiceAllowed = true;
        public bool HotspotEnabled = true;
        public bool AcceptNewClients = true;
        public bool RequireStatusShare = true;
        public HashSet<string> Authorized = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        public HashSet<string> LocalGrants = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        public HashSet<string> Pending = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
    }

    private static void LoadState()
    {
        try
        {
            if (File.Exists(InfoPath))
            {
                long mt = File.GetLastWriteTimeUtc(InfoPath).Ticks;
                if (mt != _infoMtime)
                {
                    string raw = File.ReadAllText(InfoPath, Encoding.UTF8);
                    string url = JsonGet(raw, "portal_url");
                    if (url.Length > 0)
                    {
                        _state.PortalUrl = url;
                    }
                    string name = JsonGet(raw, "store_name");
                    if (name.Length > 0)
                    {
                        _state.StoreName = name;
                    }
                    string ssid = JsonGet(raw, "wifi_ssid");
                    if (ssid.Length > 0)
                    {
                        _state.Ssid = ssid;
                    }
                    _infoMtime = mt;
                }
            }
            if (File.Exists(AuthPath))
            {
                long mt = File.GetLastWriteTimeUtc(AuthPath).Ticks;
                if (mt != _authMtime)
                {
                    string raw = File.ReadAllText(AuthPath, Encoding.UTF8);
                    var nextAuth = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
                    var nextLocal = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
                    foreach (string ip in JsonGetStringArray(raw, "authorized"))
                    {
                        if (ip.Length > 0)
                        {
                            nextAuth.Add(ip);
                        }
                    }
                    foreach (string ip in JsonGetStringArray(raw, "local_grants"))
                    {
                        if (ip.Length > 0)
                        {
                            nextLocal.Add(ip);
                            nextAuth.Add(ip);
                        }
                    }
                    string portal = JsonGet(raw, "portal_url");
                    if (portal.Length > 0)
                    {
                        _state.PortalUrl = portal;
                    }
                    string pip = JsonGet(raw, "portal_ip");
                    if (pip.Length > 0)
                    {
                        _state.PortalIp = pip;
                    }
                    string sn = JsonGet(raw, "store_name");
                    if (sn.Length > 0)
                    {
                        _state.StoreName = sn;
                    }
                    string tpl = JsonGet(raw, "status_template");
                    if (tpl.Length > 0)
                    {
                        _state.StatusTemplate = tpl;
                    }
                    string ssid = JsonGet(raw, "ssid");
                    if (ssid.Length > 0)
                    {
                        _state.Ssid = ssid;
                    }
                    int maxClients;
                    if (int.TryParse(JsonGet(raw, "max_clients"), out maxClients) && maxClients > 0)
                    {
                        _state.MaxClients = maxClients;
                    }
                    int hours;
                    if (int.TryParse(JsonGet(raw, "session_hours"), out hours) && hours > 0)
                    {
                        _state.SessionHours = hours;
                    }
                    string mode = JsonGet(raw, "approval_mode");
                    if (mode.Length > 0)
                    {
                        _state.ApprovalMode = mode.ToLowerInvariant();
                    }
                    _state.ServiceAllowed = JsonFlag(raw, "service_allowed", true);
                    _state.HotspotEnabled = JsonFlag(raw, "hotspot_enabled", true);
                    _state.AcceptNewClients = JsonFlag(raw, "accept_new_clients", true);
                    _state.RequireStatusShare = JsonFlag(raw, "require_status_share", true);
                    string title = JsonGet(raw, "portal_title");
                    if (title.Length > 0)
                    {
                        _state.PortalTitle = title;
                    }
                    string sub = JsonGet(raw, "portal_subtitle");
                    if (sub.Length > 0)
                    {
                        _state.PortalSubtitle = sub;
                    }
                    var pending = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
                    foreach (string ip in JsonGetStringArray(raw, "pending_ips"))
                    {
                        if (ip.Length > 0)
                        {
                            pending.Add(ip);
                        }
                    }
                    _state.Pending = pending;
                    _state.Authorized = nextAuth;
                    _state.LocalGrants = nextLocal;
                    _authMtime = mt;
                }
            }
        }
        catch
        {
        }
    }

    private static void Handle(HttpListenerContext ctx)
    {
        try
        {
            LoadState();
            if (!IsPortalNetworkRequest(ctx.Request))
            {
                ctx.Response.StatusCode = 404;
                ctx.Response.Close();
                return;
            }
            string path = (ctx.Request.Url.AbsolutePath ?? "/").TrimEnd('/');
            if (path.Length == 0)
            {
                path = "/";
            }
            string ip = ctx.Request.RemoteEndPoint != null ? ctx.Request.RemoteEndPoint.Address.ToString() : "";
            bool authorized = ip.Length > 0 && _state.Authorized.Contains(ip);
            string method = (ctx.Request.HttpMethod ?? "GET").ToUpperInvariant();

            if (!_state.ServiceAllowed || !_state.HotspotEnabled)
            {
                WriteHtml(ctx, 200, BlockedPage());
                return;
            }

            if (method == "POST" && (path == "/unlock" || path == "/liberar"))
            {
                HandleUnlock(ctx, ip);
                return;
            }

            if (_state.Pending.Contains(ip) && !authorized)
            {
                WriteHtml(ctx, 200, PendingPage());
                return;
            }

            if (authorized && IsCaptiveProbe(path))
            {
                WriteProbeSuccess(ctx, path);
                return;
            }
            if (authorized)
            {
                WriteHtml(ctx, 200, SuccessPage(ip));
                return;
            }

            // Probes: SEMPRE para pagina LOCAL HTTP (popup do SO). Nunca HTTPS nuvem.
            if (IsCaptiveProbe(path))
            {
                string local = LocalLoginUrl();
                ctx.Response.StatusCode = 302;
                ctx.Response.RedirectLocation = local;
                ctx.Response.Headers["Cache-Control"] = "no-store";
                byte[] hint = Encoding.UTF8.GetBytes("<html><head><meta http-equiv=\"refresh\" content=\"0;url=" + WebUtility.HtmlEncode(local) + "\"></head><body>Login</body></html>");
                ctx.Response.ContentType = "text/html; charset=utf-8";
                ctx.Response.ContentLength64 = hint.Length;
                ctx.Response.OutputStream.Write(hint, 0, hint.Length);
                ctx.Response.Close();
                return;
            }

            WriteHtml(ctx, 200, LoginPage(ip));
        }
        catch
        {
            try
            {
                ctx.Response.StatusCode = 500;
                ctx.Response.Close();
            }
            catch
            {
            }
        }
    }

    private static bool IsPortalNetworkRequest(HttpListenerRequest request)
    {
        IPAddress portalAddress;
        if (!IPAddress.TryParse(_state.PortalIp, out portalAddress)
            || portalAddress.AddressFamily != System.Net.Sockets.AddressFamily.InterNetwork
            || request.LocalEndPoint == null
            || request.RemoteEndPoint == null
            || !request.LocalEndPoint.Address.Equals(portalAddress)
            || request.RemoteEndPoint.Address.AddressFamily != System.Net.Sockets.AddressFamily.InterNetwork)
        {
            return false;
        }

        try
        {
            foreach (NetworkInterface adapter in NetworkInterface.GetAllNetworkInterfaces())
            {
                foreach (UnicastIPAddressInformation unicast in adapter.GetIPProperties().UnicastAddresses)
                {
                    if (!unicast.Address.Equals(portalAddress) || unicast.IPv4Mask == null)
                    {
                        continue;
                    }
                    byte[] remote = request.RemoteEndPoint.Address.GetAddressBytes();
                    byte[] local = portalAddress.GetAddressBytes();
                    byte[] mask = unicast.IPv4Mask.GetAddressBytes();
                    for (int i = 0; i < 4; i++)
                    {
                        if ((remote[i] & mask[i]) != (local[i] & mask[i]))
                        {
                            return false;
                        }
                    }
                    return true;
                }
            }
        }
        catch
        {
        }
        return false;
    }

    private static void HandleUnlock(HttpListenerContext ctx, string ip)
    {
        if (string.IsNullOrWhiteSpace(ip) || ip == "127.0.0.1" || ip == "::1")
        {
            WriteJson(ctx, 400, "{\"ok\":false,\"message\":\"IP invalido\"}");
            return;
        }
        try
        {
            LoadState();
            string deny = ValidateUnlock(ip);
            if (deny.Length > 0)
            {
                WriteJson(ctx, 409, "{\"ok\":false,\"message\":\"" + JsonEscape(deny) + "\"}");
                return;
            }
            string code = RandomCode(6);
            bool manual = string.Equals(_state.ApprovalMode, "manual", StringComparison.OrdinalIgnoreCase);
            if (manual)
            {
                MarkPending(ip, code);
                WriteJson(ctx, 202, "{\"ok\":true,\"pending\":true,\"ip\":\"" + JsonEscape(ip) + "\",\"code\":\"" + JsonEscape(code) + "\",\"message\":\"Aguardando confirmacao no painel da loja.\"}");
                return;
            }
            GrantLocalIp(ip, code);
            WriteJson(ctx, 200, "{\"ok\":true,\"ip\":\"" + JsonEscape(ip) + "\",\"code\":\"" + JsonEscape(code) + "\",\"message\":\"Internet liberada\"}");
        }
        catch (Exception ex)
        {
            WriteJson(ctx, 500, "{\"ok\":false,\"message\":\"" + JsonEscape(ex.Message) + "\"}");
        }
    }

    private static string ValidateUnlock(string ip)
    {
        if (!_state.ServiceAllowed || !_state.HotspotEnabled)
        {
            return "Wi-Fi indisponivel no momento (assinatura ou hotspot).";
        }
        if (!_state.AcceptNewClients && !_state.LocalGrants.Contains(ip) && !_state.Authorized.Contains(ip))
        {
            return "Limite de clientes do plano atingido. Fale com a loja.";
        }
        if (_state.LocalGrants.Count >= _state.MaxClients && !_state.LocalGrants.Contains(ip))
        {
            return "Rede cheia. Tente de novo daqui a pouco.";
        }
        return "";
    }

    private static void GrantLocalIp(string ip, string code)
    {
        lock (AuthLock)
        {
            LoadState();
            _state.Pending.Remove(ip);
            _state.LocalGrants.Add(ip);
            _state.Authorized.Add(ip);
            WriteClientRecord(ip, code, "online");
            PersistAuthLocked();
        }
    }

    private static void PersistAuthLocked()
    {
            string raw = "";
            if (File.Exists(AuthPath))
            {
                try
                {
                    raw = File.ReadAllText(AuthPath, Encoding.UTF8);
                }
                catch
                {
                }
            }

            string allowJson = ExtractJsonArrayLiteral(raw, "allow_suffixes");
            if (allowJson.Length == 0)
            {
                allowJson = "[]";
            }

            var sb = new StringBuilder();
            sb.Append("{\n");
            sb.Append("  \"portal_ip\": \"").Append(JsonEscape(_state.PortalIp)).Append("\",\n");
            sb.Append("  \"portal_url\": \"").Append(JsonEscape(_state.PortalUrl)).Append("\",\n");
            sb.Append("  \"store_name\": \"").Append(JsonEscape(_state.StoreName)).Append("\",\n");
            sb.Append("  \"status_template\": \"").Append(JsonEscape(_state.StatusTemplate)).Append("\",\n");
            sb.Append("  \"ssid\": \"").Append(JsonEscape(_state.Ssid)).Append("\",\n");
            sb.Append("  \"authorized\": ").Append(ToJsonStringArray(_state.Authorized)).Append(",\n");
            sb.Append("  \"local_grants\": ").Append(ToJsonStringArray(_state.LocalGrants)).Append(",\n");
            sb.Append("  \"max_clients\": \"").Append(_state.MaxClients).Append("\",\n");
            sb.Append("  \"session_hours\": \"").Append(_state.SessionHours).Append("\",\n");
            sb.Append("  \"approval_mode\": \"").Append(JsonEscape(_state.ApprovalMode)).Append("\",\n");
            sb.Append("  \"service_allowed\": \"").Append(_state.ServiceAllowed ? "1" : "0").Append("\",\n");
            sb.Append("  \"hotspot_enabled\": \"").Append(_state.HotspotEnabled ? "1" : "0").Append("\",\n");
            sb.Append("  \"accept_new_clients\": \"").Append(_state.AcceptNewClients ? "1" : "0").Append("\",\n");
            sb.Append("  \"require_status_share\": \"").Append(_state.RequireStatusShare ? "1" : "0").Append("\",\n");
            sb.Append("  \"portal_title\": \"").Append(JsonEscape(_state.PortalTitle)).Append("\",\n");
            sb.Append("  \"portal_subtitle\": \"").Append(JsonEscape(_state.PortalSubtitle)).Append("\",\n");
            sb.Append("  \"pending_ips\": ").Append(ToJsonStringArray(_state.Pending)).Append(",\n");
            sb.Append("  \"connection_owner\": \"agent\",\n");
            sb.Append("  \"allow_suffixes\": ").Append(allowJson).Append(",\n");
            sb.Append("  \"updated_at\": \"").Append(JsonEscape(DateTime.Now.ToString("s"))).Append("\"\n");
            sb.Append("}\n");

            string tmp = AuthPath + ".tmp";
            File.WriteAllText(tmp, sb.ToString(), new UTF8Encoding(false));
            if (File.Exists(AuthPath))
            {
                File.Replace(tmp, AuthPath, null);
            }
            else
            {
                File.Move(tmp, AuthPath);
            }
            _authMtime = 0;
            LoadState();
    }

    private static void MarkPending(string ip, string code)
    {
        lock (AuthLock)
        {
            LoadState();
            _state.Pending.Add(ip);
            WriteClientRecord(ip, code, "pending");
            PersistAuthLocked();
        }
    }

    private static void WriteClientRecord(string ip, string code, string state = "online")
    {
        try
        {
            Directory.CreateDirectory(ClientsDir);
            string safe = (ip ?? "").Replace(':', '_').Replace('/', '_').Replace('\\', '_');
            string now = DateTime.Now.ToString("s");
            string text = BuildStatusTextForCode(code);
            string expires = "";
            if (state == "online" && _state.SessionHours > 0)
            {
                expires = DateTime.Now.AddHours(_state.SessionHours).ToString("s");
            }
            var sb = new StringBuilder();
            sb.Append("{\n");
            sb.Append("  \"ip\": \"").Append(JsonEscape(ip)).Append("\",\n");
            sb.Append("  \"mac\": \"\",\n");
            sb.Append("  \"phone\": \"\",\n");
            sb.Append("  \"status_code\": \"").Append(JsonEscape(code)).Append("\",\n");
            sb.Append("  \"status_text\": \"").Append(JsonEscape(text)).Append("\",\n");
            sb.Append("  \"state\": \"").Append(JsonEscape(state)).Append("\",\n");
            sb.Append("  \"user_agent\": \"captive-local\",\n");
            sb.Append("  \"created_at\": \"").Append(now).Append("\",\n");
            sb.Append("  \"authorized_at\": \"").Append(now).Append("\",\n");
            sb.Append("  \"expires_at\": \"").Append(JsonEscape(expires)).Append("\"\n");
            sb.Append("}\n");
            string path = Path.Combine(ClientsDir, safe + ".json");
            string tmp = path + ".tmp";
            File.WriteAllText(tmp, sb.ToString(), new UTF8Encoding(false));
            if (File.Exists(path))
            {
                File.Replace(tmp, path, null);
            }
            else
            {
                File.Move(tmp, path);
            }
        }
        catch
        {
        }
    }

    private static string BuildStatusTextForCode(string code)
    {
        string tpl = string.IsNullOrWhiteSpace(_state.StatusTemplate)
            ? "Estou na {loja} agora! Codigo {codigo}"
            : _state.StatusTemplate;
        return tpl
            .Replace("{loja}", _state.StoreName ?? "loja")
            .Replace("{codigo}", code ?? "")
            .Replace("{cidade}", "");
    }

    private static string ToJsonStringArray(IEnumerable<string> items)
    {
        var sb = new StringBuilder();
        sb.Append('[');
        bool first = true;
        foreach (string item in items)
        {
            if (string.IsNullOrWhiteSpace(item))
            {
                continue;
            }
            if (!first)
            {
                sb.Append(',');
            }
            first = false;
            sb.Append('"').Append(JsonEscape(item.Trim())).Append('"');
        }
        sb.Append(']');
        return sb.ToString();
    }

    private static string ExtractJsonArrayLiteral(string raw, string key)
    {
        if (string.IsNullOrEmpty(raw))
        {
            return "";
        }
        string needle = "\"" + key + "\"";
        int i = raw.IndexOf(needle, StringComparison.Ordinal);
        if (i < 0)
        {
            return "";
        }
        i = raw.IndexOf('[', i);
        if (i < 0)
        {
            return "";
        }
        int end = raw.IndexOf(']', i);
        if (end < 0)
        {
            return "";
        }
        return raw.Substring(i, end - i + 1);
    }

    private static string LocalLoginUrl()
    {
        string ip = string.IsNullOrWhiteSpace(_state.PortalIp) ? "192.168.137.1" : _state.PortalIp.Trim();
        return "http://" + ip + "/";
    }

    private static string BuildStatusText()
    {
        string code = RandomCode(6);
        string tpl = string.IsNullOrWhiteSpace(_state.StatusTemplate)
            ? "Estou na {loja} agora! Codigo {codigo}"
            : _state.StatusTemplate;
        return tpl
            .Replace("{loja}", _state.StoreName ?? "loja")
            .Replace("{codigo}", code)
            .Replace("{cidade}", "");
    }

    private static string RandomCode(int n)
    {
        const string alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
        var sb = new StringBuilder(n);
        var rnd = new Random();
        for (int i = 0; i < n; i++)
        {
            sb.Append(alphabet[rnd.Next(alphabet.Length)]);
        }
        return sb.ToString();
    }

    private static string LoginPage(string clientIp)
    {
        string store = WebUtility.HtmlEncode(_state.StoreName ?? "Wi-Fi da loja");
        string title = WebUtility.HtmlEncode(string.IsNullOrWhiteSpace(_state.PortalTitle) ? store : _state.PortalTitle);
        string lead = WebUtility.HtmlEncode(string.IsNullOrWhiteSpace(_state.PortalSubtitle)
            ? "Para liberar a internet, publique um status no WhatsApp e toque em Já publiquei."
            : _state.PortalSubtitle);
        string statusText = BuildStatusText();
        string wa = "https://wa.me/?text=" + Uri.EscapeDataString(statusText);
        string waSafe = WebUtility.HtmlEncode(wa);
        string statusSafe = WebUtility.HtmlEncode(statusText);

        return "<!DOCTYPE html><html lang=\"pt-BR\"><head><meta charset=\"utf-8\">" +
            "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">" +
            "<title>" + title + "</title>" +
            "<style>" +
            "body{margin:0;font-family:Segoe UI,Roboto,sans-serif;background:#0b1220;color:#f8fafc;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}" +
            ".card{max-width:420px;width:100%;background:#111827;border:1px solid #1f2937;border-radius:16px;padding:28px 24px;box-shadow:0 20px 50px rgba(0,0,0,.35)}" +
            "h1{font-size:1.45rem;margin:0 0 8px}p{line-height:1.45;color:#cbd5e1}.hint{font-size:.9rem;color:#94a3b8}" +
            "ol{padding-left:1.2rem;color:#cbd5e1}li{margin:6px 0}" +
            ".btn{display:block;width:100%;box-sizing:border-box;text-align:center;margin-top:12px;padding:14px 16px;border-radius:12px;font-weight:700;text-decoration:none;border:0;font-size:1rem;cursor:pointer}" +
            ".primary{background:#22c55e;color:#052e16}.ghost{background:transparent;color:#93c5fd;border:1px solid #334155}" +
            ".ok{display:none;margin-top:14px;padding:12px;border-radius:10px;background:#052e16;color:#86efac}" +
            ".warn{margin-top:14px;padding:10px 12px;border-radius:10px;background:#1e293b;color:#fbbf24;font-size:.85rem}" +
            "</style></head><body><div class=\"card\">" +
            "<h1>" + title + "</h1>" +
            "<p>" + lead + "</p>" +
            "<ol>" +
            "<li>Toque em <strong>Publicar no WhatsApp</strong></li>" +
            "<li>Escolha <strong>Status</strong> (não um contato)</li>" +
            "<li>Volte e toque em <strong>Já publiquei — liberar</strong></li>" +
            "</ol>" +
            "<p class=\"hint\">Texto sugerido:<br>" + statusSafe + "</p>" +
            "<a class=\"btn primary\" id=\"wa\" href=\"" + waSafe + "\">Publicar no WhatsApp</a>" +
            "<button class=\"btn ghost\" type=\"button\" id=\"unlock\">Já publiquei — liberar internet</button>" +
            "<div class=\"ok\" id=\"ok\">Internet liberada neste aparelho. Pode navegar.</div>" +
            "<div class=\"warn\">Se o popup não abrir sozinho, abra no navegador: <strong>http://192.168.137.1</strong><br>" +
            "No Android, desative <strong>DNS privado</strong> (Configurações → Rede → DNS privado → Desativado) enquanto usa o Wi-Fi da loja.</div>" +
            "<script>" +
            "document.getElementById('unlock').onclick=async function(){" +
            "this.disabled=true;this.textContent='Liberando…';" +
            "try{var r=await fetch('/unlock',{method:'POST'});var j=await r.json();" +
            "if(j&&j.ok&&j.pending){location.reload();return;}" +
            "if(j&&j.ok){document.getElementById('ok').style.display='block';this.textContent='Liberado';" +
            "setTimeout(function(){location.href='https://www.google.com/generate_204'},800);}" +
            "else{alert((j&&j.message)||'Falha ao liberar');this.disabled=false;this.textContent='Já publiquei — liberar internet';}}" +
            "catch(e){alert('Falha de rede local');this.disabled=false;this.textContent='Já publiquei — liberar internet';}" +
            "};</script></div></body></html>";
    }

    private static string SuccessPage(string ip)
    {
        string store = WebUtility.HtmlEncode(_state.StoreName ?? "Wi-Fi da loja");
        return SimplePage("Liberado", "<h1>Internet liberada</h1><p>" + store + "</p><p><a href=\"https://www.google.com/\" style=\"color:#86efac\">Abrir Google</a></p>");
    }

    private static string BlockedPage()
    {
        string store = WebUtility.HtmlEncode(_state.StoreName ?? "Wi-Fi da loja");
        return SimplePage("Indisponivel", "<h1>Wi-Fi indisponivel</h1><p>" + store + "</p><p>Assinatura ou hotspot bloqueado no painel.</p>");
    }

    private static string PendingPage()
    {
        return SimplePage("Aguardando", "<h1>Aguardando o balcao</h1><p>O painel da loja precisa confirmar. Depois a internet libera sozinha.</p><p><button onclick=\"location.reload()\" style=\"margin-top:12px;padding:10px 16px\">Atualizar</button></p>");
    }

    private static string SimplePage(string title, string inner)
    {
        return "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">" +
            "<title>" + WebUtility.HtmlEncode(title) + "</title>" +
            "<style>body{font-family:Segoe UI,sans-serif;background:#0b1220;color:#f8fafc;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px;text-align:center}</style>" +
            "</head><body><div>" + inner + "</div></body></html>";
    }

    private static string ResolveCloudPortalUrl()
    {
        string url = _state.PortalUrl;
        if (!IsLoopbackUrl(url))
        {
            return url;
        }
        try
        {
            if (File.Exists(CloudPath))
            {
                string raw = File.ReadAllText(CloudPath, Encoding.UTF8);
                string panel = JsonGet(raw, "panel_url").TrimEnd('/');
                string portalToken = File.Exists(InfoPath)
                    ? JsonGet(File.ReadAllText(InfoPath, Encoding.UTF8), "portal_token")
                    : "";
                if (panel.Length > 8 && portalToken.Length > 0 && !IsLoopbackUrl(panel))
                {
                    return panel + "/portal/" + Uri.EscapeDataString(portalToken);
                }
            }
        }
        catch
        {
        }
        return url ?? "";
    }

    private static bool IsLoopbackUrl(string url)
    {
        if (string.IsNullOrWhiteSpace(url))
        {
            return true;
        }
        try
        {
            var uri = new Uri(url);
            string host = (uri.Host ?? "").Trim().ToLowerInvariant();
            return host == "" || host == "localhost" || host == "127.0.0.1" || host == "::1";
        }
        catch
        {
            return true;
        }
    }

    private static bool IsCaptiveProbe(string path)
    {
        switch (path.ToLowerInvariant())
        {
            case "/":
                return false;
            case "/generate_204":
            case "/gen_204":
            case "/hotspot-detect.html":
            case "/canonical.html":
            case "/ncsi.txt":
            case "/connecttest.txt":
            case "/success.txt":
            case "/redirect":
            case "/library/test/success.html":
                return true;
            default:
                return false;
        }
    }

    private static void WriteProbeSuccess(HttpListenerContext ctx, string path)
    {
        path = path.ToLowerInvariant();
        if (path == "/generate_204" || path == "/gen_204")
        {
            ctx.Response.StatusCode = 204;
            ctx.Response.Close();
            return;
        }
        if (path == "/hotspot-detect.html" || path == "/canonical.html" || path == "/library/test/success.html")
        {
            byte[] body = Encoding.UTF8.GetBytes("<HTML><HEAD><TITLE>Success</TITLE></HEAD><BODY>Success</BODY></HTML>");
            ctx.Response.StatusCode = 200;
            ctx.Response.ContentType = "text/html";
            ctx.Response.ContentLength64 = body.Length;
            ctx.Response.OutputStream.Write(body, 0, body.Length);
            ctx.Response.Close();
            return;
        }
        byte[] txt = Encoding.UTF8.GetBytes("Microsoft NCSI");
        ctx.Response.StatusCode = 200;
        ctx.Response.ContentType = "text/plain";
        ctx.Response.ContentLength64 = txt.Length;
        ctx.Response.OutputStream.Write(txt, 0, txt.Length);
        ctx.Response.Close();
    }

    private static void WriteHtml(HttpListenerContext ctx, int code, string html)
    {
        byte[] body = Encoding.UTF8.GetBytes(html);
        ctx.Response.StatusCode = code;
        ctx.Response.ContentType = "text/html; charset=utf-8";
        ctx.Response.Headers["Cache-Control"] = "no-store";
        ctx.Response.ContentLength64 = body.Length;
        ctx.Response.OutputStream.Write(body, 0, body.Length);
        ctx.Response.Close();
    }

    private static void WriteJson(HttpListenerContext ctx, int code, string json)
    {
        byte[] body = Encoding.UTF8.GetBytes(json);
        ctx.Response.StatusCode = code;
        ctx.Response.ContentType = "application/json; charset=utf-8";
        ctx.Response.Headers["Cache-Control"] = "no-store";
        ctx.Response.ContentLength64 = body.Length;
        ctx.Response.OutputStream.Write(body, 0, body.Length);
        ctx.Response.Close();
    }

    private static string JsonEscape(string s)
    {
        if (s == null)
        {
            return "";
        }
        return s.Replace("\\", "\\\\").Replace("\"", "\\\"").Replace("\r", "").Replace("\n", " ");
    }

    private static bool JsonFlag(string raw, string key, bool fallback)
    {
        string v = JsonGet(raw, key).Trim().ToLowerInvariant();
        if (v.Length == 0)
        {
            return fallback;
        }
        return v == "1" || v == "true" || v == "yes";
    }

    private static string JsonGet(string raw, string key)
    {
        if (string.IsNullOrEmpty(raw))
        {
            return "";
        }
        string needle = "\"" + key + "\"";
        int i = raw.IndexOf(needle, StringComparison.Ordinal);
        if (i < 0)
        {
            return "";
        }
        i = raw.IndexOf(':', i);
        if (i < 0)
        {
            return "";
        }
        i++;
        while (i < raw.Length && char.IsWhiteSpace(raw[i]))
        {
            i++;
        }
        if (i >= raw.Length || raw[i] != '"')
        {
            return "";
        }
        i++;
        var sb = new StringBuilder();
        while (i < raw.Length && raw[i] != '"')
        {
            if (raw[i] == '\\' && i + 1 < raw.Length)
            {
                i++;
            }
            sb.Append(raw[i]);
            i++;
        }
        return sb.ToString();
    }

    private static IEnumerable<string> JsonGetStringArray(string raw, string key)
    {
        var list = new List<string>();
        if (string.IsNullOrEmpty(raw))
        {
            return list;
        }
        string needle = "\"" + key + "\"";
        int i = raw.IndexOf(needle, StringComparison.Ordinal);
        if (i < 0)
        {
            return list;
        }
        i = raw.IndexOf('[', i);
        if (i < 0)
        {
            return list;
        }
        int end = raw.IndexOf(']', i);
        if (end < 0)
        {
            return list;
        }
        string chunk = raw.Substring(i, end - i + 1);
        int j = 0;
        while (j < chunk.Length)
        {
            int q1 = chunk.IndexOf('"', j);
            if (q1 < 0)
            {
                break;
            }
            int q2 = chunk.IndexOf('"', q1 + 1);
            if (q2 < 0)
            {
                break;
            }
            list.Add(chunk.Substring(q1 + 1, q2 - q1 - 1));
            j = q2 + 1;
        }
        return list;
    }
}
