using System;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.IO;
using System.IO.Pipes;
using System.Text;
using System.Windows.Forms;

internal static class AgentPipeClient
{
    private const string PipeName = "WiFiDaLojaAgent";

    public static bool SendOk(string cmd, int timeoutMs)
    {
        string resp = SendRaw(cmd, timeoutMs);
        if (string.IsNullOrEmpty(resp))
        {
            return false;
        }
        return resp.IndexOf("\"ok\":true", StringComparison.OrdinalIgnoreCase) >= 0
            || resp.IndexOf("\"ok\": true", StringComparison.OrdinalIgnoreCase) >= 0;
    }

    public static string SendRaw(string cmd, int timeoutMs)
    {
        try
        {
            using (var client = new NamedPipeClientStream(".", PipeName, PipeDirection.InOut))
            {
                client.Connect(timeoutMs);
                using (var writer = new StreamWriter(client, new UTF8Encoding(false)) { AutoFlush = true })
                using (var reader = new StreamReader(client, Encoding.UTF8))
                {
                    writer.WriteLine("{\"cmd\":\"" + Escape(cmd) + "\"}");
                    return reader.ReadLine() ?? "";
                }
            }
        }
        catch
        {
            return "";
        }
    }

    private static string Escape(string s)
    {
        return (s ?? "").Replace("\\", "\\\\").Replace("\"", "\\\"");
    }
}

internal sealed class TrayApp : ApplicationContext
{
    private static readonly Color Bg = Color.FromArgb(11, 15, 20);
    private static readonly Color Card = Color.FromArgb(20, 27, 34);
    private static readonly Color Ink = Color.FromArgb(238, 243, 248);
    private static readonly Color Muted = Color.FromArgb(141, 154, 171);
    private static readonly Color Line = Color.FromArgb(36, 48, 60);
    private static readonly Color Gold = Color.FromArgb(232, 176, 88);
    private static readonly Color Ok = Color.FromArgb(125, 186, 122);
    private static readonly Color Danger = Color.FromArgb(224, 112, 96);

    private readonly string root;
    private readonly NotifyIcon icon;
    private readonly ContextMenuStrip menu;
    private readonly Timer timer;
    private StatusForm statusForm;

    public TrayApp()
    {
        root = AppDomain.CurrentDomain.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar);
        try
        {
            ImportLegacyStorage(root);
            Directory.CreateDirectory(AgentDataDir());
        }
        catch
        {
        }

        menu = BuildMenu();
        icon = new NotifyIcon
        {
            Icon = MakeAppIcon(),
            Visible = true,
            Text = "Wi-Fi da loja",
            ContextMenuStrip = menu
        };
        icon.MouseUp += Icon_MouseUp;
        icon.DoubleClick += delegate { SafeShowStatus(); };

        timer = new Timer { Interval = 3000 };
        timer.Tick += delegate
        {
            try
            {
                UpdateTip();
                if (statusForm != null && !statusForm.IsDisposed && statusForm.Visible)
                {
                    statusForm.RefreshData();
                }
            }
            catch
            {
            }
        };
        timer.Start();
        UpdateTip();
    }

    private ContextMenuStrip BuildMenu()
    {
        var strip = new ContextMenuStrip();
        strip.Items.Add("Abrir status", null, delegate { SafeShowStatus(); });
        strip.Items.Add("Abrir painel do cliente", null, delegate { OpenUrl(ClientPanelUrl()); });
        strip.Items.Add("Abrir hotspot no painel", null, delegate { OpenUrl(AdminPanelUrl()); });
        strip.Items.Add(new ToolStripSeparator());
        strip.Items.Add("Ligar rede", null, delegate { WriteCommand("start"); });
        strip.Items.Add("Desligar rede", null, delegate { WriteCommand("stop"); });
        strip.Items.Add("Vincular hotspot", null, delegate { SafeBindStore(); });
        strip.Items.Add(new ToolStripSeparator());
        strip.Items.Add("Encerrar", null, delegate { ExitApp(); });
        return strip;
    }

    private void Icon_MouseUp(object sender, MouseEventArgs e)
    {
        try
        {
            if (e.Button == MouseButtons.Left)
            {
                SafeShowStatus();
                return;
            }
            if (e.Button == MouseButtons.Right)
            {
                menu.Show(Cursor.Position);
            }
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Wi-Fi da loja", MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
    }

    private void SafeShowStatus()
    {
        try
        {
            ShowStatus();
        }
        catch (Exception ex)
        {
            MessageBox.Show("Nao foi possivel abrir o status:\n" + ex.Message, "Wi-Fi da loja", MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
    }

    private void SafeBindStore()
    {
        try
        {
            BindStore();
        }
        catch (Exception ex)
        {
            MessageBox.Show("Nao foi possivel abrir a vinculacao:\n" + ex.Message, "Wi-Fi da loja", MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
    }

    private string Storage(string name)
    {
        return Path.Combine(AgentDataDir(), name);
    }

    private static string AgentDataDir()
    {
        string dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "WiFiDaLoja");
        Directory.CreateDirectory(dir);
        return dir;
    }

    private static void ImportLegacyStorage(string installRoot)
    {
        string legacy = Path.Combine(installRoot, "storage");
        if (!Directory.Exists(legacy))
        {
            return;
        }
        string target = AgentDataDir();
        foreach (string name in new[] {
            "cloud.json", "authorized.json", "store-info.json", "install-mode.json",
            "sync-error.json", "client-patches.json", "php-path.txt"
        })
        {
            string src = Path.Combine(legacy, name);
            string dst = Path.Combine(target, name);
            if (File.Exists(src) && !File.Exists(dst))
            {
                File.Copy(src, dst);
            }
        }
        string legacyBrand = Path.Combine(legacy, "brand");
        string targetBrand = Path.Combine(target, "brand");
        if (Directory.Exists(legacyBrand) && !Directory.Exists(targetBrand))
        {
            Directory.CreateDirectory(targetBrand);
            foreach (string file in Directory.GetFiles(legacyBrand))
            {
                File.Copy(file, Path.Combine(targetBrand, Path.GetFileName(file)));
            }
        }
    }

    private bool PingAgentService()
    {
        return AgentPipeClient.SendOk("ping", 800);
    }

    private bool AgentRecentlyActive(int maxAgeSeconds)
    {
        try
        {
            string path = Storage("status.json");
            if (File.Exists(path))
            {
                double fileAge = (DateTime.Now - File.GetLastWriteTime(path)).TotalSeconds;
                if (fileAge <= maxAgeSeconds)
                {
                    return true;
                }
            }
            string raw = ReadFileSafe(path);
            string seen = JsonGet(raw, "agent_seen_at");
            DateTime dt;
            if (DateTime.TryParse(seen, out dt))
            {
                return (DateTime.Now - dt).TotalSeconds <= maxAgeSeconds;
            }
        }
        catch
        {
        }
        return false;
    }

    private bool WaitAgentActive(int maxWaitSeconds)
    {
        for (int i = 0; i < maxWaitSeconds * 2; i++)
        {
            if (AgentRecentlyActive(60) || PingAgentService())
            {
                return true;
            }
            System.Threading.Thread.Sleep(500);
        }
        return false;
    }

    private bool EnsureAgentRunning()
    {
        if (AgentRecentlyActive(60) || PingAgentService())
        {
            return true;
        }
        return WaitAgentActive(3);
    }

    private void QueueCommandFile(string action)
    {
        string id = Guid.NewGuid().ToString("N").Substring(0, 16);
        string json = "{\n  \"id\": \"" + id + "\",\n  \"action\": \"" + action + "\",\n  \"at\": \"" + DateTime.Now.ToString("o") + "\"\n}\n";
        Directory.CreateDirectory(AgentDataDir());
        string commandsDir = Path.Combine(AgentDataDir(), "commands");
        Directory.CreateDirectory(commandsDir);
        File.WriteAllText(Path.Combine(commandsDir, id + ".json"), json);
        File.WriteAllText(Storage("command.json"), json);
    }

    private void WriteCommand(string action)
    {
        string pipeCmd = action == "start" ? "start" : "stop";
        try
        {
            Directory.CreateDirectory(AgentDataDir());
            bool viaPipe = AgentPipeClient.SendOk(pipeCmd, 1500);
            if (!viaPipe)
            {
                QueueCommandFile(action);
                if (!EnsureAgentRunning())
                {
                    throw new InvalidOperationException(
                        "Servico WiFiDaLojaAgent sem resposta. Reinstale o setup v2.0.2 como administrador.");
                }
            }
        }
        catch (Exception ex)
        {
            icon.ShowBalloonTip(6000, "Wi-Fi da loja", ex.Message, ToolTipIcon.Error);
            return;
        }
        icon.ShowBalloonTip(2500, "Wi-Fi da loja", action == "start" ? "Ligando a rede..." : "Desligando a rede...", ToolTipIcon.Info);
        if (statusForm != null && !statusForm.IsDisposed)
        {
            statusForm.RefreshData();
        }
        var feedbackTimer = new Timer { Interval = 8000 };
        feedbackTimer.Tick += delegate
        {
            feedbackTimer.Stop();
            feedbackTimer.Dispose();
            string statusRaw = ReadFileSafe(Storage("status.json"));
            string err = JsonGet(statusRaw, "error");
            string syncErr = JsonGet(statusRaw, "sync_error");
            if (err.Length == 0 && syncErr.Length > 0)
            {
                err = syncErr;
            }
            bool on = JsonGet(statusRaw, "hotspot_on") == "true";
            if (action == "start")
            {
                if (on)
                {
                    icon.ShowBalloonTip(3000, "Wi-Fi da loja", "Rede ligada.", ToolTipIcon.Info);
                }
                else if (err.Length > 0)
                {
                    icon.ShowBalloonTip(9000, "Wi-Fi da loja", err, ToolTipIcon.Error);
                }
                else if (!AgentRecentlyActive(60) && !PingAgentService())
                {
                    icon.ShowBalloonTip(
                        9000,
                        "Wi-Fi da loja",
                        "Agente sem resposta. Reinstale o setup v2.0.2 como administrador.",
                        ToolTipIcon.Warning);
                }
                else
                {
                    icon.ShowBalloonTip(
                        8000,
                        "Wi-Fi da loja",
                        "Agente ativo, mas o hotspot nao ligou. Abra Configuracoes > Rede > Ponto de acesso movel e ligue uma vez manualmente.",
                        ToolTipIcon.Warning);
                }
            }
            else if (action == "stop" && !on)
            {
                icon.ShowBalloonTip(2500, "Wi-Fi da loja", "Rede desligada.", ToolTipIcon.Info);
            }
            UpdateTip();
            if (statusForm != null && !statusForm.IsDisposed)
            {
                statusForm.RefreshData();
            }
        };
        feedbackTimer.Start();
    }

    private void ShowStatus()
    {
        if (statusForm == null || statusForm.IsDisposed)
        {
            statusForm = new StatusForm(this);
        }
        statusForm.RefreshData();
        if (!statusForm.Visible)
        {
            statusForm.Show();
        }
        if (statusForm.WindowState == FormWindowState.Minimized)
        {
            statusForm.WindowState = FormWindowState.Normal;
        }
        statusForm.ShowInTaskbar = true;
        statusForm.TopMost = true;
        statusForm.BringToFront();
        statusForm.Activate();
        statusForm.TopMost = false;
        statusForm.Focus();
    }

    private void BindStore()
    {
        using (var f = new Form())
        {
            f.Text = "Vincular hotspot";
            f.Width = 520;
            f.Height = 280;
            f.FormBorderStyle = FormBorderStyle.FixedDialog;
            f.StartPosition = FormStartPosition.CenterScreen;
            f.MaximizeBox = false;
            f.BackColor = Bg;
            f.ForeColor = Ink;
            f.Font = new Font("Segoe UI", 10f);

            var lead = new Label
            {
                Left = 20,
                Top = 16,
                Width = 460,
                Height = 36,
                Text = "Cole a URL do painel e o token do hotspot (Painel → Hotspots → Abrir).",
                ForeColor = Muted
            };
            var url = new TextBox
            {
                Left = 20,
                Top = 78,
                Width = 460,
                Height = 28,
                Text = ReadCloud("panel_url"),
                BackColor = Card,
                ForeColor = Ink,
                BorderStyle = BorderStyle.FixedSingle
            };
            var token = new TextBox
            {
                Left = 20,
                Top = 138,
                Width = 460,
                Height = 28,
                Text = ReadCloud("token"),
                BackColor = Card,
                ForeColor = Ink,
                BorderStyle = BorderStyle.FixedSingle
            };
            f.Controls.Add(lead);
            f.Controls.Add(new Label { Left = 20, Top = 56, Width = 420, Text = "Endereço do painel (https://...)", ForeColor = Muted });
            f.Controls.Add(url);
            f.Controls.Add(new Label { Left = 20, Top = 116, Width = 420, Text = "Token do hotspot", ForeColor = Muted });
            f.Controls.Add(token);
            var ok = MakeGoldButton("Salvar", 290, 190, 90, 34);
            ok.DialogResult = DialogResult.OK;
            var cancel = MakeGhostButton("Cancelar", 390, 190, 90, 34);
            cancel.DialogResult = DialogResult.Cancel;
            f.Controls.Add(ok);
            f.Controls.Add(cancel);
            f.AcceptButton = ok;
            f.CancelButton = cancel;
            if (f.ShowDialog() != DialogResult.OK) return;
            string u = url.Text.Trim().TrimEnd('/');
            string t = token.Text.Trim();
            if (u.Length < 8 || t.Length < 8)
            {
                MessageBox.Show("Informe o endereço do painel e o token do hotspot.", "Wi-Fi da Loja");
                return;
            }
            string json = "{\n  \"panel_url\": \"" + EscapeJson(u) + "\",\n  \"token\": \"" + EscapeJson(t) + "\"\n}\n";
            Directory.CreateDirectory(AgentDataDir());
            File.WriteAllText(Storage("cloud.json"), json);
            icon.ShowBalloonTip(2500, "Wi-Fi da Loja", "Hotspot vinculado. Aguarde a sincronização.", ToolTipIcon.Info);
            if (statusForm != null && !statusForm.IsDisposed)
            {
                statusForm.RefreshData();
            }
        }
    }

    internal string ReadCloud(string key)
    {
        return JsonGet(ReadFileSafe(Storage("cloud.json")), key);
    }

    internal string ReadInfo(string key)
    {
        return JsonGet(ReadFileSafe(Storage("store-info.json")), key);
    }

    internal string ReadLocalAgentVersion()
    {
        try
        {
            string path = Path.Combine(root, "AGENT_VERSION");
            if (File.Exists(path))
            {
                return File.ReadAllText(path).Trim();
            }
        }
        catch
        {
        }
        return "";
    }

    internal string ReadStatus(string key)
    {
        return JsonGet(ReadFileSafe(Storage("status.json")), key);
    }

    internal static string ReadFileSafe(string path)
    {
        try
        {
            return File.Exists(path) ? File.ReadAllText(path) : "";
        }
        catch
        {
            return "";
        }
    }

    internal static string JsonGet(string raw, string key)
    {
        try
        {
            if (string.IsNullOrEmpty(raw)) return "";
            string needle = "\"" + key + "\"";
            int i = raw.IndexOf(needle, StringComparison.OrdinalIgnoreCase);
            if (i < 0) return "";
            int colon = raw.IndexOf(':', i);
            if (colon < 0) return "";
            int p = colon + 1;
            while (p < raw.Length && char.IsWhiteSpace(raw[p])) p++;
            if (p >= raw.Length) return "";
            if (raw[p] == '"')
            {
                int q2 = FindClosingQuote(raw, p + 1);
                if (q2 < 0) return "";
                return UnescapeJson(raw.Substring(p + 1, q2 - p - 1));
            }
            if (raw.Substring(p).StartsWith("true", StringComparison.OrdinalIgnoreCase)) return "true";
            if (raw.Substring(p).StartsWith("false", StringComparison.OrdinalIgnoreCase)) return "false";
            if (raw.Substring(p).StartsWith("null", StringComparison.OrdinalIgnoreCase)) return "";
            string num = "";
            for (int j = p; j < raw.Length; j++)
            {
                char c = raw[j];
                if (char.IsDigit(c) || c == '-' || c == '.') num += c;
                else break;
            }
            return num;
        }
        catch
        {
            return "";
        }
    }

    private static int FindClosingQuote(string s, int start)
    {
        for (int i = start; i < s.Length; i++)
        {
            if (s[i] == '\\') { i++; continue; }
            if (s[i] == '"') return i;
        }
        return -1;
    }

    private static string UnescapeJson(string s)
    {
        return s.Replace("\\\"", "\"").Replace("\\\\", "\\").Replace("\\/", "/").Replace("\\n", "\n");
    }

    private static string EscapeJson(string s)
    {
        return s.Replace("\\", "\\\\").Replace("\"", "\\\"");
    }

    internal string AdminPanelUrl()
    {
        string fromInfo = ReadInfo("admin_url");
        if (fromInfo.Length > 8) return fromInfo;
        string url = ReadCloud("panel_url");
        if (url.Length > 8) return url.TrimEnd('/') + "/app";
        string cfg = Path.Combine(root, "app", "config.php");
        return File.Exists(cfg) ? "http://127.0.0.1:8080/app" : "http://127.0.0.1:8080/instalar";
    }

    internal string ClientPanelUrl()
    {
        string fromInfo = ReadInfo("client_url");
        if (fromInfo.Length > 8) return fromInfo;
        string url = ReadCloud("panel_url");
        if (url.Length > 8) return url.TrimEnd('/') + "/cliente";
        return "http://127.0.0.1:8080/cliente";
    }

    internal void OpenUrl(string url)
    {
        if (string.IsNullOrEmpty(url)) return;
        try { Process.Start(url); }
        catch { Process.Start(new ProcessStartInfo { FileName = url, UseShellExecute = true }); }
    }

    private void UpdateTip()
    {
        try
        {
            string name = ReadInfo("store_name");
            string bill = ReadInfo("billing_label");
            if (bill.Length == 0) bill = ReadInfo("billing_status");
            string raw = ReadFileSafe(Storage("status.json"));
            bool on = JsonGet(raw, "hotspot_on") == "true";
            string clients = JsonGet(raw, "windows_clients");
            string max = ReadInfo("max_clients");
            if (max.Length == 0) max = "8";
            if (clients.Length == 0) clients = "0";
            string company = ReadInfo("company_name");
            string tip = (name.Length > 0 ? name : "Wi-Fi da Loja");
            if (company.Length > 0) tip = company + " · " + tip;
            tip += " · " + (on ? "Rede ligada" : "Rede desligada");
            tip += " · " + clients + "/" + max;
            if (bill.Length > 0) tip += " · " + bill;
            string syncErr = JsonGet(ReadFileSafe(Storage("sync-error.json")), "error");
            if (syncErr.Length > 0) tip += " · sync erro";
            if (tip.Length > 63) tip = tip.Substring(0, 60) + "...";
            icon.Text = tip;
        }
        catch
        {
            icon.Text = "Wi-Fi da loja";
        }
    }

    private void ExitApp()
    {
        try
        {
            timer.Stop();
            if (statusForm != null && !statusForm.IsDisposed)
            {
                statusForm.Close();
            }
            icon.Visible = false;
            icon.Dispose();
            menu.Dispose();
        }
        catch
        {
        }
        Application.Exit();
    }

    private static Button MakeGoldButton(string text, int x, int y, int w, int h)
    {
        return new Button
        {
            Text = text,
            Left = x,
            Top = y,
            Width = w,
            Height = h,
            FlatStyle = FlatStyle.Flat,
            BackColor = Gold,
            ForeColor = Color.FromArgb(26, 19, 8),
            Font = new Font("Segoe UI", 9f, FontStyle.Bold),
            Cursor = Cursors.Hand
        };
    }

    private static Button MakeGhostButton(string text, int x, int y, int w, int h)
    {
        var b = new Button
        {
            Text = text,
            Left = x,
            Top = y,
            Width = w,
            Height = h,
            FlatStyle = FlatStyle.Flat,
            BackColor = Color.Transparent,
            ForeColor = Ink,
            Font = new Font("Segoe UI", 9f),
            Cursor = Cursors.Hand
        };
        b.FlatAppearance.BorderColor = Line;
        return b;
    }

    private static Icon MakeAppIcon()
    {
        var bmp = new Bitmap(32, 32);
        using (var g = Graphics.FromImage(bmp))
        {
            g.SmoothingMode = SmoothingMode.AntiAlias;
            g.Clear(Color.Transparent);
            using (var brush = new SolidBrush(Gold))
            {
                g.FillEllipse(brush, 2, 2, 28, 28);
            }
            using (var font = new Font("Segoe UI", 10f, FontStyle.Bold))
            using (var brush = new SolidBrush(Color.FromArgb(26, 19, 8)))
            {
                var sf = new StringFormat { Alignment = StringAlignment.Center, LineAlignment = StringAlignment.Center };
                g.DrawString("WL", font, brush, new RectangleF(0, 1, 32, 32), sf);
            }
        }
        return Icon.FromHandle(bmp.GetHicon());
    }

    [STAThread]
    public static void Main()
    {
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);
        try
        {
            Application.Run(new TrayApp());
        }
        catch (Exception ex)
        {
            MessageBox.Show(
                "Erro ao iniciar a bandeja:\n" + ex.Message,
                "Wi-Fi da loja",
                MessageBoxButtons.OK,
                MessageBoxIcon.Error);
        }
    }

    private sealed class StatusForm : Form
    {
        private readonly TrayApp app;
        private readonly Label storeNameLbl;
        private readonly Label storeCityLbl;
        private readonly Label licenseTag;
        private readonly Label licenseDetail;
        private readonly Label planLbl;
        private readonly Label hotspotTag;
        private readonly Label ssidLbl;
        private readonly Label passLbl;
        private readonly Label clientsLbl;
        private readonly Label portalLbl;
        private readonly Label syncLbl;
        private readonly Label boundLbl;
        private readonly LinkLabel adminLink;
        private readonly LinkLabel clientLink;
        private bool showPass;

        public StatusForm(TrayApp owner)
        {
            app = owner;
            Text = "Wi-Fi da loja";
            Width = 560;
            Height = 640;
            FormBorderStyle = FormBorderStyle.FixedSingle;
            MaximizeBox = false;
            MinimizeBox = true;
            StartPosition = FormStartPosition.CenterScreen;
            BackColor = Bg;
            ForeColor = Ink;
            Font = new Font("Segoe UI", 10f);
            Icon = MakeAppIcon();

            var header = Section("Hotspot", 16, 16, 512, 108);
            storeNameLbl = Field(header, "—", 16, 36, 480, 28, new Font("Segoe UI", 16f, FontStyle.Bold), Ink);
            storeCityLbl = Field(header, "", 16, 68, 480, 22, Font, Muted);

            var lic = Section("Licença / assinatura", 16, 136, 512, 118);
            licenseTag = Field(lic, "—", 16, 36, 200, 26, new Font("Segoe UI", 11f, FontStyle.Bold), Gold);
            licenseDetail = Field(lic, "", 16, 64, 480, 22, Font, Muted);
            planLbl = Field(lic, "", 16, 86, 480, 22, Font, Muted);

            var links = Section("Painel", 16, 266, 512, 110);
            Field(links, "Gestão da assinatura e pagamentos", 16, 34, 300, 20, new Font("Segoe UI", 8.5f), Muted);
            clientLink = MakeLink(links, "Abrir portal do cliente", 16, 56, 280, 22);
            clientLink.LinkClicked += delegate { app.OpenUrl(app.ClientPanelUrl()); };
            Field(links, "Painel da empresa (hotspots)", 16, 78, 300, 18, new Font("Segoe UI", 8.5f), Muted);
            adminLink = MakeLink(links, "Abrir hotspot no painel", 300, 56, 180, 22);
            adminLink.LinkClicked += delegate { app.OpenUrl(app.AdminPanelUrl()); };

            var hot = Section("Hotspot", 16, 388, 512, 150);
            hotspotTag = Field(hot, "—", 16, 34, 180, 24, new Font("Segoe UI", 11f, FontStyle.Bold), Ok);
            ssidLbl = Field(hot, "SSID: —", 16, 62, 340, 20, Font, Ink);
            passLbl = Field(hot, "Senha: ••••••••", 16, 84, 340, 20, Font, Muted);
            clientsLbl = Field(hot, "Clientes: —", 16, 106, 200, 20, Font, Muted);
            portalLbl = Field(hot, "Portal: —", 220, 106, 260, 20, Font, Muted);

            var showPassBtn = MakeGhostButton("Mostrar senha", 360, 58, 120, 28);
            showPassBtn.Click += delegate
            {
                showPass = !showPass;
                showPassBtn.Text = showPass ? "Ocultar senha" : "Mostrar senha";
                RefreshData();
            };
            hot.Controls.Add(showPassBtn);

            var startBtn = MakeGoldButton("Ligar", 16, 552, 100, 34);
            startBtn.Click += delegate { app.WriteCommand("start"); };
            var stopBtn = MakeGhostButton("Desligar", 126, 552, 100, 34);
            stopBtn.Click += delegate { app.WriteCommand("stop"); };
            var bindBtn = MakeGhostButton("Vincular…", 236, 552, 110, 34);
            bindBtn.Click += delegate { app.SafeBindStore(); };
            var refreshBtn = MakeGhostButton("Atualizar", 356, 552, 100, 34);
            refreshBtn.Click += delegate { RefreshData(); };

            boundLbl = new Label
            {
                Left = 16,
                Top = 592,
                Width = 340,
                Height = 18,
                ForeColor = Muted,
                Font = new Font("Segoe UI", 8.5f),
                Text = ""
            };
            syncLbl = new Label
            {
                Left = 360,
                Top = 592,
                Width = 170,
                Height = 18,
                ForeColor = Muted,
                Font = new Font("Segoe UI", 8.5f),
                TextAlign = ContentAlignment.MiddleRight,
                Text = ""
            };

            Controls.Add(startBtn);
            Controls.Add(stopBtn);
            Controls.Add(bindBtn);
            Controls.Add(refreshBtn);
            Controls.Add(boundLbl);
            Controls.Add(syncLbl);
        }

        private Panel Section(string title, int x, int y, int w, int h)
        {
            var p = new Panel
            {
                Left = x,
                Top = y,
                Width = w,
                Height = h,
                BackColor = Card
            };
            p.Controls.Add(new Label
            {
                Left = 16,
                Top = 10,
                Width = w - 32,
                Height = 18,
                Text = title.ToUpperInvariant(),
                Font = new Font("Segoe UI", 8f, FontStyle.Bold),
                ForeColor = Gold
            });
            Controls.Add(p);
            return p;
        }

        private static Label Field(Control parent, string text, int x, int y, int w, int h, Font font, Color color)
        {
            var l = new Label
            {
                Left = x,
                Top = y,
                Width = w,
                Height = h,
                Text = text,
                Font = font,
                ForeColor = color
            };
            parent.Controls.Add(l);
            return l;
        }

        private static LinkLabel MakeLink(Control parent, string text, int x, int y, int w, int h)
        {
            var l = new LinkLabel
            {
                Left = x,
                Top = y,
                Width = w,
                Height = h,
                Text = text,
                LinkColor = Gold,
                ActiveLinkColor = CreamSafe(),
                VisitedLinkColor = Gold,
                Font = new Font("Segoe UI", 10f, FontStyle.Bold)
            };
            parent.Controls.Add(l);
            return l;
        }

        private static Color CreamSafe()
        {
            return Color.FromArgb(250, 244, 232);
        }

        public void RefreshData()
        {
            string name = app.ReadInfo("store_name");
            if (name.Length == 0) name = "Hotspot ainda não sincronizado";
            storeNameLbl.Text = name;

            string company = app.ReadInfo("company_name");
            string city = app.ReadInfo("store_city");
            if (company.Length > 0 && city.Length > 0)
            {
                storeCityLbl.Text = company + " · " + city;
            }
            else if (company.Length > 0)
            {
                storeCityLbl.Text = company;
            }
            else if (city.Length > 0)
            {
                storeCityLbl.Text = city;
            }
            else
            {
                storeCityLbl.Text = "Vincule o PC ao painel para carregar os dados.";
            }

            string bill = app.ReadInfo("billing_label");
            string billKey = app.ReadInfo("billing_status");
            if (bill.Length == 0) bill = billKey.Length > 0 ? billKey : "Aguardando sync";
            licenseTag.Text = bill;
            licenseTag.ForeColor = LicenseColor(billKey);

            string until = app.ReadInfo("paid_until");
            string trial = app.ReadInfo("trial_ends_at");
            if (until.Length > 0)
            {
                licenseDetail.Text = "Vigente até " + FormatDate(until);
            }
            else if (trial.Length > 0)
            {
                licenseDetail.Text = "Trial até " + FormatDate(trial);
            }
            else
            {
                licenseDetail.Text = app.ReadCloud("token").Length > 0
                    ? "Aguardando primeira sincronização com o painel…"
                    : "PC ainda não vinculado ao painel.";
            }

            string updated = app.ReadInfo("updated_at");
            string syncErr = TrayApp.JsonGet(TrayApp.ReadFileSafe(app.Storage("sync-error.json")), "error");
            if (syncErr.Length > 0)
            {
                syncLbl.Text = "Erro de sync";
                syncLbl.ForeColor = Danger;
                if (licenseDetail.Text.StartsWith("Aguardando") || licenseDetail.Text.StartsWith("PC ainda"))
                {
                    licenseDetail.Text = syncErr;
                    licenseDetail.ForeColor = Danger;
                }
            }
            else
            {
                syncLbl.ForeColor = Muted;
                syncLbl.Text = updated.Length > 0 ? "Sync " + updated.Replace("T", " ") : "";
                if (licenseDetail.ForeColor == Danger && (until.Length > 0 || trial.Length > 0))
                {
                    licenseDetail.ForeColor = Muted;
                }
            }

            string scope = app.ReadInfo("subscription_scope");
            string plan = app.ReadInfo("plan_label");
            string amount = app.ReadInfo("cycle_amount");
            string active = app.ReadInfo("active");
            string planLine = plan.Length > 0 ? "Plano " + plan : "Plano —";
            if (scope == "company") planLine += " (assinatura da empresa)";
            if (amount.Length > 0) planLine += " · R$ " + amount;
            if (active == "false") planLine += " · serviço suspenso";
            planLbl.Text = planLine;

            string statusRaw = TrayApp.ReadFileSafe(app.Storage("status.json"));
            bool on = TrayApp.JsonGet(statusRaw, "hotspot_on") == "true";
            string statusErr = TrayApp.JsonGet(statusRaw, "error");
            hotspotTag.Text = on ? "Rede ligada" : "Rede desligada";
            hotspotTag.ForeColor = on ? Ok : Danger;
            if (!on && statusErr.Length > 0)
            {
                hotspotTag.Text = "Erro ao ligar";
                hotspotTag.ForeColor = Danger;
                ssidLbl.Text = statusErr.Length > 120 ? statusErr.Substring(0, 117) + "..." : statusErr;
            }
            else
            {
            string ssid = app.ReadInfo("wifi_ssid");
            if (ssid.Length == 0) ssid = TrayApp.JsonGet(statusRaw, "ssid");
            ssidLbl.Text = "SSID: " + (ssid.Length > 0 ? ssid : "—");

            string pass = app.ReadInfo("wifi_pass");
            if (showPass && pass.Length > 0) passLbl.Text = "Senha: " + pass;
            else if (pass.Length > 0) passLbl.Text = "Senha: ••••••••";
            else passLbl.Text = "Senha: —";

            string clients = TrayApp.JsonGet(statusRaw, "windows_clients");
            string max = app.ReadInfo("max_clients");
            if (max.Length == 0) max = "8";
            if (clients.Length == 0) clients = "0";
            clientsLbl.Text = "Clientes: " + clients + "/" + max;

            string portal = app.ReadInfo("portal_ip");
            if (portal.Length == 0) portal = "192.168.137.1";
            portalLbl.Text = "Portal: " + portal;

            string panel = app.ReadCloud("panel_url");
            boundLbl.Text = panel.Length > 8 ? "Vinculado a " + panel : "Não vinculado ao painel";
            string agentVer = app.ReadInfo("agent_version");
            if (agentVer.Length == 0)
            {
                agentVer = app.ReadLocalAgentVersion();
            }
            if (agentVer.Length > 0)
            {
                boundLbl.Text = "Agente v" + agentVer + " · " + boundLbl.Text;
            }

            clientLink.Text = "Abrir portal do cliente";
            adminLink.Text = "Abrir hotspot no painel";
            }
        }

        private static Color LicenseColor(string status)
        {
            switch ((status ?? "").ToLowerInvariant())
            {
                case "ativa":
                case "trial":
                case "cortesia":
                    return Ok;
                case "pendente":
                    return Gold;
                case "atrasada":
                case "suspensa":
                case "cancelada":
                case "encerrada":
                    return Danger;
                default:
                    return Muted;
            }
        }

        private static string FormatDate(string iso)
        {
            DateTime dt;
            if (DateTime.TryParse(iso, out dt))
            {
                return dt.ToString("dd/MM/yyyy");
            }
            return iso;
        }
    }
}
