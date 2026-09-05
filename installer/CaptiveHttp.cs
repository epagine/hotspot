using System;
using System.Collections.Generic;
using System.IO;
using System.Net;
using System.Text;
using System.Threading;

internal static class CaptiveHttp
{
    private static readonly string AuthPath;
    private static readonly string InfoPath;
    private static long _authMtime;
    private static long _infoMtime;
    private static CaptiveState _state = new CaptiveState();

    static CaptiveHttp()
    {
        string dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "WiFiDaLoja");
        Directory.CreateDirectory(dir);
        AuthPath = Path.Combine(dir, "authorized.json");
        InfoPath = Path.Combine(dir, "store-info.json");
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
        Console.Error.WriteLine("Captive HTTP em :80");
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
        public HashSet<string> Authorized = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
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
                    _infoMtime = mt;
                }
            }
            if (File.Exists(AuthPath))
            {
                long mt = File.GetLastWriteTimeUtc(AuthPath).Ticks;
                if (mt != _authMtime)
                {
                    string raw = File.ReadAllText(AuthPath, Encoding.UTF8);
                    var next = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
                    foreach (string ip in JsonGetStringArray(raw, "authorized"))
                    {
                        if (ip.Length > 0)
                        {
                            next.Add(ip);
                        }
                    }
                    string portal = JsonGet(raw, "portal_url");
                    if (portal.Length > 0)
                    {
                        _state.PortalUrl = portal;
                    }
                    _state.Authorized = next;
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
            string path = ctx.Request.Url.AbsolutePath ?? "/";
            string ip = ctx.Request.RemoteEndPoint != null ? ctx.Request.RemoteEndPoint.Address.ToString() : "";
            bool authorized = _state.Authorized.Contains(ip);
            bool probe = IsCaptiveProbe(path);

            if (authorized && probe)
            {
                WriteProbeSuccess(ctx, path);
                return;
            }
            if (authorized)
            {
                ctx.Response.StatusCode = 204;
                ctx.Response.Close();
                return;
            }
            string target = _state.PortalUrl;
            if (target.Length == 0)
            {
                target = "http://192.168.137.1/";
            }
            ctx.Response.StatusCode = 302;
            ctx.Response.RedirectLocation = target;
            ctx.Response.Close();
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

    private static bool IsCaptiveProbe(string path)
    {
        switch (path.ToLowerInvariant())
        {
            case "/generate_204":
            case "/gen_204":
            case "/hotspot-detect.html":
            case "/canonical.html":
            case "/ncsi.txt":
            case "/connecttest.txt":
            case "/success.txt":
            case "/redirect":
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
        if (path == "/hotspot-detect.html" || path == "/canonical.html")
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
