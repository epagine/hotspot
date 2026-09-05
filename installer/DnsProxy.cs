using System;
using System.Collections.Generic;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Text;

internal static class DnsProxy
{
    private const string Upstream = "8.8.8.8";
    private static readonly string StatePath;
    private static long _stateMtime;
    private static CaptiveState _state = new CaptiveState();

    static DnsProxy()
    {
        string dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "WiFiDaLoja");
        Directory.CreateDirectory(dir);
        StatePath = Path.Combine(dir, "authorized.json");
    }

    public static void Main()
    {
        var listen = new UdpClient(new IPEndPoint(IPAddress.Any, 53));
        Console.Error.WriteLine("DNS cativo em 0.0.0.0:53");
        while (true)
        {
            IPEndPoint remote = new IPEndPoint(IPAddress.Any, 0);
            byte[] buf;
            try
            {
                buf = listen.Receive(ref remote);
            }
            catch
            {
                continue;
            }
            if (buf == null || buf.Length < 12)
            {
                continue;
            }
            CaptiveState st = LoadState();
            string qname = ParseQname(buf);
            string clientIp = remote.Address.ToString();
            bool authorized = st.Authorized.Contains(clientIp);
            bool allowed = qname.Length > 0 && DomainAllowed(qname, st.AllowSuffixes);
            byte[] reply = null;
            if (authorized || allowed)
            {
                reply = ForwardDns(buf);
            }
            if (reply == null)
            {
                reply = SpoofA(buf, st.PortalIp);
            }
            if (reply != null && reply.Length >= 12)
            {
                try
                {
                    listen.Send(reply, reply.Length, remote);
                }
                catch
                {
                }
            }
        }
    }

    private sealed class CaptiveState
    {
        public string PortalIp = "192.168.137.1";
        public HashSet<string> Authorized = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        public List<string> AllowSuffixes = new List<string>();
    }

    private static CaptiveState LoadState()
    {
        try
        {
            if (!File.Exists(StatePath))
            {
                return _state;
            }
            long mt = File.GetLastWriteTimeUtc(StatePath).Ticks;
            if (mt == _stateMtime)
            {
                return _state;
            }
            string raw = File.ReadAllText(StatePath, Encoding.UTF8);
            var next = new CaptiveState();
            string portal = JsonGet(raw, "portal_ip");
            if (portal.Length > 0)
            {
                next.PortalIp = portal;
            }
            foreach (string ip in JsonGetStringArray(raw, "authorized"))
            {
                if (ip.Length > 0)
                {
                    next.Authorized.Add(ip);
                }
            }
            next.AllowSuffixes.AddRange(JsonGetStringArray(raw, "allow_suffixes"));
            _state = next;
            _stateMtime = mt;
        }
        catch
        {
        }
        return _state;
    }

    private static bool DomainAllowed(string name, List<string> suffixes)
    {
        name = name.Trim().TrimEnd('.').ToLowerInvariant();
        foreach (string suf in suffixes)
        {
            string s = suf.Trim().TrimStart('.').ToLowerInvariant();
            if (s.Length == 0)
            {
                continue;
            }
            if (name == s || name.EndsWith("." + s, StringComparison.Ordinal))
            {
                return true;
            }
        }
        return false;
    }

    private static string ParseQname(byte[] pkt)
    {
        int pos = 12;
        var labels = new List<string>();
        while (pos < pkt.Length)
        {
            int n = pkt[pos];
            if (n == 0)
            {
                break;
            }
            if ((n & 0xC0) == 0xC0)
            {
                break;
            }
            pos++;
            if (pos + n > pkt.Length)
            {
                return "";
            }
            labels.Add(Encoding.ASCII.GetString(pkt, pos, n));
            pos += n;
        }
        return string.Join(".", labels.ToArray());
    }

    private static byte[] SpoofA(byte[] query, string ip)
    {
        if (query.Length < 12)
        {
            return null;
        }
        ushort id = ReadU16(query, 0);
        int pos = 12;
        int len = query.Length;
        while (pos < len && query[pos] != 0)
        {
            int n = query[pos];
            if ((n & 0xC0) == 0xC0)
            {
                pos += 2;
                break;
            }
            pos += n + 1;
        }
        if (pos >= len)
        {
            return null;
        }
        if (query[pos] == 0)
        {
            pos++;
        }
        if (pos + 4 > len)
        {
            return null;
        }
        ushort qtype = ReadU16(query, pos);
        int questionEnd = pos + 4;
        byte[] question = new byte[questionEnd - 12];
        Array.Copy(query, 12, question, 0, question.Length);
        var reply = new List<byte>();
        WriteU16(reply, id);
        WriteU16(reply, 0x8180);
        WriteU16(reply, 1);
        if (qtype == 1)
        {
            WriteU16(reply, 1);
            WriteU16(reply, 0);
            WriteU16(reply, 0);
            reply.AddRange(question);
            reply.Add(0xC0);
            reply.Add(0x0C);
            WriteU16(reply, 1);
            WriteU16(reply, 1);
            WriteU32(reply, 30);
            WriteU16(reply, 4);
            IPAddress addr;
            if (!IPAddress.TryParse(ip, out addr))
            {
                addr = IPAddress.Parse("192.168.137.1");
            }
            reply.AddRange(addr.GetAddressBytes());
        }
        else
        {
            WriteU16(reply, 0);
            WriteU16(reply, 0);
            WriteU16(reply, 0);
            reply.AddRange(question);
        }
        return reply.ToArray();
    }

    private static byte[] ForwardDns(byte[] query)
    {
        try
        {
            using (var client = new UdpClient())
            {
                client.Client.ReceiveTimeout = 1500;
                client.Send(query, query.Length, Upstream, 53);
                IPEndPoint ep = new IPEndPoint(IPAddress.Any, 0);
                return client.Receive(ref ep);
            }
        }
        catch
        {
            return null;
        }
    }

    private static ushort ReadU16(byte[] b, int i)
    {
        return (ushort)((b[i] << 8) | b[i + 1]);
    }

    private static void WriteU16(List<byte> buf, ushort v)
    {
        buf.Add((byte)(v >> 8));
        buf.Add((byte)(v & 0xFF));
    }

    private static void WriteU32(List<byte> buf, uint v)
    {
        buf.Add((byte)((v >> 24) & 0xFF));
        buf.Add((byte)((v >> 16) & 0xFF));
        buf.Add((byte)((v >> 8) & 0xFF));
        buf.Add((byte)(v & 0xFF));
    }

    private static string JsonGet(string raw, string key)
    {
        try
        {
            if (string.IsNullOrEmpty(raw))
            {
                return "";
            }
            string needle = "\"" + key + "\"";
            int i = raw.IndexOf(needle, StringComparison.OrdinalIgnoreCase);
            if (i < 0)
            {
                return "";
            }
            int colon = raw.IndexOf(':', i);
            if (colon < 0)
            {
                return "";
            }
            int p = colon + 1;
            while (p < raw.Length && char.IsWhiteSpace(raw[p]))
            {
                p++;
            }
            if (p >= raw.Length || raw[p] != '"')
            {
                return "";
            }
            int q2 = raw.IndexOf('"', p + 1);
            if (q2 < 0)
            {
                return "";
            }
            return UnescapeJson(raw.Substring(p + 1, q2 - p - 1));
        }
        catch
        {
            return "";
        }
    }

    private static List<string> JsonGetStringArray(string raw, string key)
    {
        var list = new List<string>();
        try
        {
            string needle = "\"" + key + "\"";
            int i = raw.IndexOf(needle, StringComparison.OrdinalIgnoreCase);
            if (i < 0)
            {
                return list;
            }
            int bracket = raw.IndexOf('[', i);
            if (bracket < 0)
            {
                return list;
            }
            int end = raw.IndexOf(']', bracket);
            if (end < 0)
            {
                return list;
            }
            string chunk = raw.Substring(bracket + 1, end - bracket - 1);
            int p = 0;
            while (p < chunk.Length)
            {
                int q1 = chunk.IndexOf('"', p);
                if (q1 < 0)
                {
                    break;
                }
                int q2 = chunk.IndexOf('"', q1 + 1);
                if (q2 < 0)
                {
                    break;
                }
                list.Add(UnescapeJson(chunk.Substring(q1 + 1, q2 - q1 - 1)));
                p = q2 + 1;
            }
        }
        catch
        {
        }
        return list;
    }

    private static string UnescapeJson(string s)
    {
        return s.Replace("\\\"", "\"").Replace("\\\\", "\\").Replace("\\/", "/").Replace("\\n", "\n");
    }
}
