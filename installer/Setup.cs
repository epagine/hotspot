using System;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.IO;
using System.IO.Compression;
using System.Reflection;
using System.Text.RegularExpressions;
using System.Windows.Forms;

internal sealed class SetupForm : Form
{
    private static readonly Color Bg = Color.FromArgb(11, 15, 20);
    private static readonly Color Card = Color.FromArgb(20, 27, 34);
    private static readonly Color FieldBg = Color.FromArgb(14, 20, 26);
    private static readonly Color Ink = Color.FromArgb(238, 243, 248);
    private static readonly Color Muted = Color.FromArgb(141, 154, 171);
    private static readonly Color Line = Color.FromArgb(36, 48, 60);
    private static readonly Color Gold = Color.FromArgb(232, 176, 88);

    private readonly TextBox pathBox;
    private readonly TextBox urlBox;
    private readonly TextBox tokenBox;
    private readonly TextBox logBox;
    private readonly Label statusLabel;
    private readonly ProgressPanel bar;
    private readonly Button installBtn;
    private bool installing;

    public SetupForm()
    {
        Text = "WiFi da Loja — Agente Windows";
        Width = 700;
        Height = 600;
        StartPosition = FormStartPosition.CenterScreen;
        FormBorderStyle = FormBorderStyle.FixedSingle;
        MaximizeBox = false;
        BackColor = Bg;
        ForeColor = Ink;
        Font = new Font("Segoe UI", 10f);

        var logo = LoadEmbeddedLogo();
        if (logo != null)
        {
            Icon = IconFromImage(logo, 32);
        }

        var header = new HeaderPanel(logo);
        Controls.Add(header);

        var card = new Panel
        {
            Left = 24,
            Top = 136,
            Width = 652,
            Height = 292,
            BackColor = Card
        };
        card.Controls.Add(SectionTitle(card, "Configuração", 16, 12));
        card.Controls.Add(FieldLabel(card, "Pasta de instalação", 16, 44));
        pathBox = MakeInput(16, 64, 468);
        card.Controls.Add(pathBox);
        var browse = MakeGhostButton("Procurar…", 492, 62, 144, 32);
        browse.Click += delegate
        {
            using (var dlg = new FolderBrowserDialog())
            {
                if (dlg.ShowDialog() == DialogResult.OK && dlg.SelectedPath.Length > 0)
                {
                    pathBox.Text = dlg.SelectedPath;
                    LoadExistingCloudFields(dlg.SelectedPath);
                }
            }
        };
        card.Controls.Add(browse);

        card.Controls.Add(FieldLabel(card, "URL do painel", 16, 108));
        urlBox = MakeInput(16, 128, 620);
        card.Controls.Add(urlBox);

        card.Controls.Add(FieldLabel(card, "Token do hotspot", 16, 172));
        tokenBox = MakeInput(16, 192, 620);
        card.Controls.Add(tokenBox);

        card.Controls.Add(new Label
        {
            Left = 16,
            Top = 232,
            Width = 620,
            Height = 48,
            ForeColor = Muted,
            Font = new Font("Segoe UI", 8.5f),
            Text = "Copie URL e token em Painel → Hotspots → Abrir. Em atualizações, os campos são preenchidos automaticamente."
        });
        Controls.Add(card);

        bar = new ProgressPanel { Left = 24, Top = 444, Width = 652, Height = 8 };
        Controls.Add(bar);

        statusLabel = new Label
        {
            Left = 24,
            Top = 458,
            Width = 652,
            Height = 22,
            ForeColor = Muted,
            Font = new Font("Segoe UI", 9f),
            Text = "Pronto para instalar."
        };
        Controls.Add(statusLabel);

        logBox = new TextBox
        {
            Left = 24,
            Top = 484,
            Width = 652,
            Height = 56,
            Multiline = true,
            ReadOnly = true,
            ScrollBars = ScrollBars.Vertical,
            BackColor = FieldBg,
            ForeColor = Muted,
            BorderStyle = BorderStyle.FixedSingle,
            Font = new Font("Consolas", 8.5f),
            Visible = false
        };
        Controls.Add(logBox);

        pathBox.Text = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "WiFiDaLoja");
        pathBox.TextChanged += delegate { LoadExistingCloudFields(pathBox.Text.Trim()); };
        LoadExistingCloudFields(pathBox.Text);

        installBtn = MakeGoldButton("Instalar agente", 424, 512, 160, 40);
        installBtn.Click += delegate { RunInstall(); };

        var cancel = MakeGhostButton("Cancelar", 592, 512, 84, 40);
        cancel.Click += delegate { Close(); };

        Controls.Add(installBtn);
        Controls.Add(cancel);

        Controls.Add(new Label
        {
            Left = 24,
            Top = 520,
            Width = 380,
            Height = 32,
            ForeColor = Color.FromArgb(100, 112, 128),
            Font = new Font("Segoe UI", 8f),
            Text = "Windows 10/11 · execute como administrador"
        });
    }

    private static Label SectionTitle(Control parent, string text, int x, int y)
    {
        var l = new Label
        {
            Left = x,
            Top = y,
            Width = 400,
            Height = 20,
            Text = text.ToUpperInvariant(),
            Font = new Font("Segoe UI", 8f, FontStyle.Bold),
            ForeColor = Gold
        };
        return l;
    }

    private static Label FieldLabel(Control parent, string text, int x, int y)
    {
        return new Label
        {
            Left = x,
            Top = y,
            Width = 500,
            Height = 18,
            Text = text,
            ForeColor = Muted,
            Font = new Font("Segoe UI", 9f)
        };
    }

    private TextBox MakeInput(int x, int y, int w)
    {
        return new TextBox
        {
            Left = x,
            Top = y,
            Width = w,
            Height = 28,
            BackColor = FieldBg,
            ForeColor = Ink,
            BorderStyle = BorderStyle.FixedSingle,
            Font = new Font("Segoe UI", 10f)
        };
    }

    private static Button MakeGoldButton(string text, int x, int y, int w, int h)
    {
        var b = new Button
        {
            Text = text,
            Left = x,
            Top = y,
            Width = w,
            Height = h,
            FlatStyle = FlatStyle.Flat,
            BackColor = Gold,
            ForeColor = Color.FromArgb(26, 19, 8),
            Font = new Font("Segoe UI", 9.5f, FontStyle.Bold),
            Cursor = Cursors.Hand
        };
        b.FlatAppearance.BorderSize = 0;
        return b;
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
            BackColor = Card,
            ForeColor = Ink,
            Font = new Font("Segoe UI", 9f),
            Cursor = Cursors.Hand
        };
        b.FlatAppearance.BorderColor = Line;
        b.FlatAppearance.BorderSize = 1;
        return b;
    }

    private static Image LoadEmbeddedLogo()
    {
        try
        {
            var asm = Assembly.GetExecutingAssembly();
            using (var stream = asm.GetManifestResourceStream("WiFiDaLoja.Logo"))
            {
                if (stream != null)
                {
                    return Image.FromStream(stream);
                }
            }
        }
        catch
        {
        }
        return null;
    }

    private static Icon IconFromImage(Image source, int size)
    {
        var bmp = new Bitmap(size, size);
        using (var g = Graphics.FromImage(bmp))
        {
            g.SmoothingMode = SmoothingMode.AntiAlias;
            g.InterpolationMode = InterpolationMode.HighQualityBicubic;
            g.Clear(Color.Transparent);
            float scale = Math.Min((float)size / source.Width, (float)size / source.Height);
            float w = source.Width * scale;
            float h = source.Height * scale;
            g.DrawImage(source, (size - w) / 2f, (size - h) / 2f, w, h);
        }
        return Icon.FromHandle(bmp.GetHicon());
    }

    private void Log(string msg)
    {
        logBox.AppendText(msg + Environment.NewLine);
    }

    private void RunInstall()
    {
        if (installing)
        {
            Close();
            return;
        }
        string dest = pathBox.Text.Trim();
        string panelUrl = urlBox.Text.Trim().TrimEnd('/');
        string token = tokenBox.Text.Trim();
        bool isUpdate = HasExistingCloudConfig(dest);
        if (panelUrl.Length < 8 || token.Length < 8)
        {
            string existingUrl;
            string existingToken;
            if (TryReadCloudJson(GetCloudJsonPath(dest), out existingUrl, out existingToken))
            {
                if (panelUrl.Length < 8) { panelUrl = existingUrl; }
                if (token.Length < 8) { token = existingToken; }
                isUpdate = true;
            }
        }
        if (dest.Length < 8)
        {
            MessageBox.Show("Escolha uma pasta de instalação válida.", Text, MessageBoxButtons.OK, MessageBoxIcon.Warning);
            return;
        }
        if (panelUrl.Length < 8 || token.Length < 8)
        {
            MessageBox.Show("Informe a URL do painel e o token do hotspot.", Text, MessageBoxButtons.OK, MessageBoxIcon.Warning);
            return;
        }
        installing = true;
        installBtn.Enabled = false;
        try
        {
            statusLabel.Text = "Extraindo arquivos…";
            statusLabel.ForeColor = Ink;
            bar.Value = 15;
            Application.DoEvents();
            Directory.CreateDirectory(dest);
            string zipPath = Path.Combine(Path.GetTempPath(), "wifidaloja-agent.zip");
            ExtractPayload(zipPath);
            using (var zip = ZipFile.OpenRead(zipPath))
            {
                foreach (var e in zip.Entries)
                {
                    if (string.IsNullOrEmpty(e.Name) && e.FullName.EndsWith("/"))
                    {
                        Directory.CreateDirectory(Path.Combine(dest, e.FullName.Replace('/', Path.DirectorySeparatorChar)));
                        continue;
                    }
                    string outPath = Path.Combine(dest, e.FullName.Replace('/', Path.DirectorySeparatorChar));
                    string dir = Path.GetDirectoryName(outPath);
                    if (!string.IsNullOrEmpty(dir))
                    {
                        Directory.CreateDirectory(dir);
                    }
                    if (e.Length > 0 || !string.IsNullOrEmpty(e.Name))
                    {
                        e.ExtractToFile(outPath, true);
                    }
                }
            }
            try { File.Delete(zipPath); } catch { }

            statusLabel.Text = "Configurando Windows…";
            bar.Value = 70;
            Application.DoEvents();
            string setupPs1 = Path.Combine(dest, "scripts", "instalar-windows.ps1");
            string args = "-NoProfile -ExecutionPolicy Bypass -File \"" + setupPs1 + "\" -Cloud -PanelUrl \"" + panelUrl.Replace("\"", "`\"") + "\" -Token \"" + token.Replace("\"", "`\"") + "\"";
            if (isUpdate)
            {
                args += " -Update";
            }
            var psi = new ProcessStartInfo
            {
                FileName = "powershell.exe",
                Arguments = args,
                WorkingDirectory = dest,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true
            };
            Process p = Process.Start(psi);
            string output = p.StandardOutput.ReadToEnd() + p.StandardError.ReadToEnd();
            p.WaitForExit();
            logBox.Visible = true;
            Height = 640;
            Log(output);
            if (p.ExitCode != 0)
            {
                throw new Exception("Falha na configuração do Windows. Veja os detalhes abaixo.");
            }
            bar.Value = 100;
            statusLabel.Text = "Instalação concluída.";
            statusLabel.ForeColor = Color.FromArgb(125, 186, 122);
            installBtn.Text = "Fechar";
            installBtn.Enabled = true;
            MessageBox.Show(
                isUpdate
                    ? "Agente atualizado.\n\nSeu vínculo com o painel foi mantido."
                    : "Agente instalado com sucesso.\n\nO ícone na bandeja deve sincronizar com o painel em alguns segundos.",
                "WiFi da Loja",
                MessageBoxButtons.OK,
                MessageBoxIcon.Information);
        }
        catch (Exception ex)
        {
            installing = false;
            logBox.Visible = true;
            Height = 640;
            Log("Erro: " + ex.Message);
            statusLabel.Text = ex.Message;
            statusLabel.ForeColor = Color.FromArgb(224, 112, 96);
            installBtn.Enabled = true;
            MessageBox.Show(ex.Message, Text, MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
    }

    private static void ExtractPayload(string zipPath)
    {
        string self = Assembly.GetExecutingAssembly().Location;
        using (var fs = File.OpenRead(self))
        {
            fs.Seek(-8, SeekOrigin.End);
            var lenBuf = new byte[8];
            fs.Read(lenBuf, 0, 8);
            long zipLen = BitConverter.ToInt64(lenBuf, 0);
            if (zipLen < 100 || zipLen > fs.Length - 8)
            {
                throw new Exception("Pacote inválido. Gere de novo com installer\\Empacotar.ps1");
            }
            fs.Seek(-8 - zipLen, SeekOrigin.End);
            using (var outFs = File.Create(zipPath))
            {
                var buf = new byte[65536];
                long left = zipLen;
                while (left > 0)
                {
                    int n = fs.Read(buf, 0, (int)Math.Min(buf.Length, left));
                    if (n <= 0)
                    {
                        break;
                    }
                    outFs.Write(buf, 0, n);
                    left -= n;
                }
            }
        }
    }

    private static string ProgramDataDir()
    {
        return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "WiFiDaLoja");
    }

    private static string GetCloudJsonPath(string installDir)
    {
        string programData = Path.Combine(ProgramDataDir(), "cloud.json");
        if (File.Exists(programData))
        {
            return programData;
        }
        return Path.Combine(installDir, "storage", "cloud.json");
    }

    private static bool TryReadCloudJson(string path, out string panelUrl, out string token)
    {
        panelUrl = "";
        token = "";
        if (!File.Exists(path))
        {
            return false;
        }
        try
        {
            string json = File.ReadAllText(path);
            panelUrl = ExtractJsonString(json, "panel_url").Trim().TrimEnd('/');
            token = ExtractJsonString(json, "token").Trim();
            return panelUrl.Length >= 8 && token.Length >= 8;
        }
        catch
        {
            return false;
        }
    }

    private static string ExtractJsonString(string json, string key)
    {
        Match m = Regex.Match(json, "\"" + Regex.Escape(key) + "\"\\s*:\\s*\"([^\"]*)\"");
        return m.Success ? m.Groups[1].Value : "";
    }

    private static bool HasExistingCloudConfig(string installDir)
    {
        string panelUrl;
        string token;
        return TryReadCloudJson(GetCloudJsonPath(installDir), out panelUrl, out token);
    }

    private void LoadExistingCloudFields(string installDir)
    {
        string panelUrl;
        string token;
        if (!TryReadCloudJson(GetCloudJsonPath(installDir), out panelUrl, out token))
        {
            return;
        }
        urlBox.Text = panelUrl;
        tokenBox.Text = token;
        statusLabel.Text = "Atualização detectada — vínculo existente será mantido.";
        statusLabel.ForeColor = Muted;
        installBtn.Text = "Atualizar agente";
    }

    [STAThread]
    public static void Main()
    {
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);
        Application.Run(new SetupForm());
    }

    private sealed class HeaderPanel : Panel
    {
        private readonly Image logo;

        public HeaderPanel(Image logoImage)
        {
            logo = logoImage;
            Left = 0;
            Top = 0;
            Width = 700;
            Height = 120;
            BackColor = Bg;
            DoubleBuffered = true;
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            var g = e.Graphics;
            g.SmoothingMode = SmoothingMode.AntiAlias;
            g.TextRenderingHint = System.Drawing.Text.TextRenderingHint.ClearTypeGridFit;

            var rect = ClientRectangle;
            using (var brush = new LinearGradientBrush(
                rect,
                Color.FromArgb(18, 26, 36),
                Bg,
                LinearGradientMode.Vertical))
            {
                g.FillRectangle(brush, rect);
            }

            using (var glow = new SolidBrush(Color.FromArgb(18, Gold)))
            {
                g.FillEllipse(glow, Width / 2 - 120, -40, 240, 100);
            }

            if (logo != null)
            {
                float maxW = 280f;
                float maxH = 56f;
                float scale = Math.Min(maxW / logo.Width, maxH / logo.Height);
                float w = logo.Width * scale;
                float h = logo.Height * scale;
                float x = (Width - w) / 2f;
                g.DrawImage(logo, x, 22, w, h);
            }
            else
            {
                using (var font = new Font("Segoe UI", 18f, FontStyle.Bold))
                using (var brush = new SolidBrush(Ink))
                {
                    var sf = new StringFormat { Alignment = StringAlignment.Center };
                    g.DrawString("WiFi da Loja", font, brush, new RectangleF(0, 28, Width, 32), sf);
                }
            }

            using (var font = new Font("Segoe UI", 9f))
            using (var brush = new SolidBrush(Muted))
            {
                var sf = new StringFormat { Alignment = StringAlignment.Center };
                g.DrawString("Agente Windows · conecte o PC da loja ao painel na nuvem", font, brush, new RectangleF(0, 84, Width, 20), sf);
            }

            using (var pen = new Pen(Gold, 2f))
            {
                g.DrawLine(pen, 24, Height - 1, Width - 24, Height - 1);
            }
        }
    }

    private sealed class ProgressPanel : Control
    {
        private int value;

        public ProgressPanel()
        {
            SetStyle(ControlStyles.AllPaintingInWmPaint | ControlStyles.UserPaint | ControlStyles.OptimizedDoubleBuffer, true);
            BackColor = FieldBg;
        }

        public int Value
        {
            get { return value; }
            set
            {
                this.value = Math.Max(0, Math.Min(100, value));
                Invalidate();
            }
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            var g = e.Graphics;
            g.SmoothingMode = SmoothingMode.AntiAlias;
            var rect = new Rectangle(0, 0, Width - 1, Height - 1);
            using (var track = new SolidBrush(FieldBg))
            {
                g.FillRectangle(track, rect);
            }
            if (value > 0)
            {
                int fillW = Math.Max(4, (int)Math.Round(Width * (value / 100.0)));
                var fillRect = new Rectangle(0, 0, fillW, Height);
                using (var brush = new LinearGradientBrush(fillRect, Gold, Color.FromArgb(200, 150, 70), LinearGradientMode.Horizontal))
                {
                    g.FillRectangle(brush, fillRect);
                }
            }
            using (var pen = new Pen(Line))
            {
                g.DrawRectangle(pen, rect);
            }
        }
    }
}
