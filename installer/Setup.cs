using System;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.IO;
using System.IO.Compression;
using System.Reflection;
using System.Windows.Forms;

internal sealed class SetupForm : Form
{
    private static readonly Color Bg = Color.FromArgb(18, 16, 14);
    private static readonly Color Panel = Color.FromArgb(28, 24, 20);
    private static readonly Color Gold = Color.FromArgb(232, 176, 88);
    private static readonly Color Cream = Color.FromArgb(250, 244, 232);
    private static readonly Color Muted = Color.FromArgb(196, 184, 164);
    private static readonly Color Line = Color.FromArgb(58, 50, 40);

    private readonly TextBox pathBox;
    private readonly TextBox urlBox;
    private readonly TextBox tokenBox;
    private readonly TextBox logBox;
    private readonly Label statusLabel;
    private readonly Label stepLabel;
    private readonly ProgressBar bar;
    private readonly Button installBtn;
    private readonly Button cancelBtn;
    private readonly Button browseBtn;
    private readonly Button detailsBtn;
    private bool installing;

    public SetupForm()
    {
        Text = "Wi-Fi da loja — Instalação";
        Width = 760;
        Height = 580;
        StartPosition = FormStartPosition.CenterScreen;
        FormBorderStyle = FormBorderStyle.FixedSingle;
        MaximizeBox = false;
        MinimizeBox = true;
        BackColor = Bg;
        ForeColor = Cream;
        Font = new Font("Segoe UI", 10f);
        Icon = MakeAppIcon();
        DoubleBuffered = true;

        var hero = new BrandPanel { Left = 0, Top = 0, Width = 248, Height = 580 };
        Controls.Add(hero);

        var title = MakeLabel("Instalar neste computador", 268, 24, 460, 32, new Font("Segoe UI", 18f, FontStyle.Bold), Cream);
        var lead = MakeLabel("O assistente copia o painel, o PHP e registra o atalho e o ícone na bandeja. É necessária uma conta de administrador e um adaptador Wi-Fi.", 268, 64, 460, 52, Font, Muted);

        stepLabel = MakeLabel("Pasta de destino", 268, 128, 460, 20, new Font("Segoe UI", 9f, FontStyle.Bold), Gold);
        pathBox = new TextBox
        {
            Left = 268,
            Top = 152,
            Width = 348,
            Height = 28,
            BorderStyle = BorderStyle.FixedSingle,
            BackColor = Color.FromArgb(22, 20, 18),
            ForeColor = Cream,
            Text = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "WiFiDaLoja")
        };
        browseBtn = MakeGhost("Procurar…", 624, 150, 104, 30);
        browseBtn.Click += delegate { PickFolder(); };

        var urlLbl = MakeLabel("Painel central (outra loja)", 268, 188, 460, 18, new Font("Segoe UI", 9f), Muted);
        urlBox = new TextBox
        {
            Left = 268,
            Top = 208,
            Width = 460,
            Height = 26,
            BorderStyle = BorderStyle.FixedSingle,
            BackColor = Color.FromArgb(22, 20, 18),
            ForeColor = Cream,
            Text = ""
        };
        var tokenLbl = MakeLabel("Token da loja (deixe em branco neste PC do painel)", 268, 238, 460, 18, new Font("Segoe UI", 9f), Muted);
        tokenBox = new TextBox
        {
            Left = 268,
            Top = 258,
            Width = 460,
            Height = 26,
            BorderStyle = BorderStyle.FixedSingle,
            BackColor = Color.FromArgb(22, 20, 18),
            ForeColor = Cream
        };

        bar = new ProgressBar
        {
            Left = 268,
            Top = 294,
            Width = 460,
            Height = 12,
            Style = ProgressBarStyle.Continuous
        };
        statusLabel = MakeLabel("Pronto para instalar.", 268, 312, 460, 22, Font, Muted);

        logBox = new TextBox
        {
            Left = 268,
            Top = 338,
            Width = 460,
            Height = 90,
            Multiline = true,
            ReadOnly = true,
            ScrollBars = ScrollBars.Vertical,
            BorderStyle = BorderStyle.FixedSingle,
            BackColor = Color.FromArgb(22, 20, 18),
            ForeColor = Muted,
            Font = new Font("Consolas", 8.5f),
            Visible = false
        };

        detailsBtn = MakeGhost("Ver detalhes", 268, 472, 130, 32);
        detailsBtn.Click += delegate
        {
            logBox.Visible = !logBox.Visible;
            detailsBtn.Text = logBox.Visible ? "Ocultar detalhes" : "Ver detalhes";
        };

        cancelBtn = MakeGhost("Cancelar", 478, 472, 110, 36);
        cancelBtn.Click += delegate { if (!installing) Close(); };
        installBtn = MakeGold("Instalar agora", 598, 472, 130, 36);
        installBtn.Click += delegate { RunInstall(); };

        Controls.Add(title);
        Controls.Add(lead);
        Controls.Add(stepLabel);
        Controls.Add(pathBox);
        Controls.Add(browseBtn);
        Controls.Add(urlLbl);
        Controls.Add(urlBox);
        Controls.Add(tokenLbl);
        Controls.Add(tokenBox);
        Controls.Add(bar);
        Controls.Add(statusLabel);
        Controls.Add(logBox);
        Controls.Add(detailsBtn);
        Controls.Add(cancelBtn);
        Controls.Add(installBtn);
    }

    private static Label MakeLabel(string text, int x, int y, int w, int h, Font font, Color color)
    {
        return new Label
        {
            Text = text,
            Left = x,
            Top = y,
            Width = w,
            Height = h,
            Font = font,
            ForeColor = color,
            BackColor = Color.Transparent
        };
    }

    private Button MakeGold(string text, int x, int y, int w, int h)
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
            Font = new Font("Segoe UI", 10f, FontStyle.Bold),
            Cursor = Cursors.Hand
        };
        b.FlatAppearance.BorderSize = 0;
        return b;
    }

    private Button MakeGhost(string text, int x, int y, int w, int h)
    {
        var b = new Button
        {
            Text = text,
            Left = x,
            Top = y,
            Width = w,
            Height = h,
            FlatStyle = FlatStyle.Flat,
            BackColor = Panel,
            ForeColor = Cream,
            Cursor = Cursors.Hand
        };
        b.FlatAppearance.BorderColor = Line;
        return b;
    }

    private void PickFolder()
    {
        using (var dlg = new FolderBrowserDialog())
        {
            dlg.Description = "Escolha a pasta de instalação";
            dlg.SelectedPath = Directory.Exists(pathBox.Text) ? pathBox.Text : Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles);
            if (dlg.ShowDialog(this) == DialogResult.OK)
            {
                pathBox.Text = dlg.SelectedPath;
            }
        }
    }

    private void Log(string line)
    {
        if (string.IsNullOrWhiteSpace(line))
        {
            return;
        }
        logBox.AppendText(line.TrimEnd() + Environment.NewLine);
        Application.DoEvents();
    }

    private void SetStatus(string step, string status, int pct)
    {
        stepLabel.Text = step;
        statusLabel.Text = status;
        bar.Value = Math.Max(0, Math.Min(100, pct));
        Application.DoEvents();
    }

    private void RunInstall()
    {
        if (installing)
        {
            Close();
            return;
        }
        string dest = pathBox.Text.Trim();
        if (dest.Length < 8 || dest.EndsWith(":\\") || dest.EndsWith(":"))
        {
            MessageBox.Show(this, "Escolha uma pasta de instalação válida, por exemplo em Arquivos de Programas.", "Wi-Fi da loja", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            return;
        }
        installing = true;
        installBtn.Enabled = false;
        cancelBtn.Enabled = false;
        browseBtn.Enabled = false;
        pathBox.Enabled = false;
        urlBox.Enabled = false;
        tokenBox.Enabled = false;
        try
        {
            SetStatus("1 de 3 · Arquivos", "Extraindo o pacote…", 8);
            Directory.CreateDirectory(dest);
            string zipPath = Path.Combine(Path.GetTempPath(), "wifidaloja-payload.zip");
            ExtractPayload(zipPath);
            SetStatus("1 de 3 · Arquivos", "Copiando o sistema…", 28);
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
            SetStatus("2 de 3 · PHP", "Preparando o runtime…", 68);
            string php = Path.Combine(dest, "runtime", "php", "php.exe");
            string storage = Path.Combine(dest, "storage");
            Directory.CreateDirectory(storage);
            if (File.Exists(php))
            {
                File.WriteAllText(Path.Combine(storage, "php-path.txt"), php);
                Log("PHP empacotado encontrado.");
            }
            else
            {
                Log("Aviso: PHP empacotado não encontrado.");
            }
            string panelUrl = urlBox.Text.Trim().TrimEnd('/');
            string token = tokenBox.Text.Trim();
            if (panelUrl.Length > 8 && token.Length >= 8)
            {
                string cloud = "{\"panel_url\":\"" + panelUrl.Replace("\\", "\\\\").Replace("\"", "\\\"") + "\",\"token\":\"" + token.Replace("\\", "\\\\").Replace("\"", "\\\"") + "\"}";
                File.WriteAllText(Path.Combine(storage, "cloud.json"), cloud);
                Log("Loja vinculada ao painel central.");
            }
            SetStatus("3 de 3 · Windows", "Registrando atalho, firewall e tarefas…", 84);
            string setupPs1 = Path.Combine(dest, "scripts", "instalar-windows.ps1");
            var psi = new ProcessStartInfo
            {
                FileName = "powershell.exe",
                Arguments = "-NoProfile -ExecutionPolicy Bypass -File \"" + setupPs1 + "\"",
                WorkingDirectory = dest,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true
            };
            Process p = Process.Start(psi);
            string output = p.StandardOutput.ReadToEnd() + p.StandardError.ReadToEnd();
            p.WaitForExit();
            Log(output);
            if (p.ExitCode != 0)
            {
                throw new Exception("A configuração do Windows falhou. Abra os detalhes para ver o registro.");
            }
            SetStatus("Concluído", "Instalação finalizada. Use o atalho ou o ícone ao lado do relógio.", 100);
            installBtn.Text = "Fechar";
            installBtn.Enabled = true;
            cancelBtn.Visible = false;
            MessageBox.Show(this, "O Wi-Fi da loja está instalado.\nAbra o atalho ou o ícone na bandeja e complete a configuração inicial no painel.", "Instalação concluída", MessageBoxButtons.OK, MessageBoxIcon.Information);
        }
        catch (Exception ex)
        {
            installing = false;
            logBox.Visible = true;
            detailsBtn.Text = "Ocultar detalhes";
            Log("Erro: " + ex.Message);
            SetStatus("Interrompido", ex.Message, bar.Value);
            installBtn.Enabled = true;
            cancelBtn.Enabled = true;
            browseBtn.Enabled = true;
            pathBox.Enabled = true;
            urlBox.Enabled = true;
            tokenBox.Enabled = true;
            MessageBox.Show(this, ex.Message, "Instalação", MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
    }

    private static void ExtractPayload(string zipPath)
    {
        string self = Assembly.GetExecutingAssembly().Location;
        using (var fs = File.OpenRead(self))
        {
            if (fs.Length < 16)
            {
                throw new Exception("Instalador incompleto. Gere de novo com installer\\Empacotar.ps1");
            }
            fs.Seek(-8, SeekOrigin.End);
            var lenBuf = new byte[8];
            fs.Read(lenBuf, 0, 8);
            long zipLen = BitConverter.ToInt64(lenBuf, 0);
            if (zipLen < 100 || zipLen > fs.Length - 8)
            {
                throw new Exception("Pacote não encontrado neste .exe. Use o WiFiDaLoja-Setup.exe gerado pelo empacotador.");
            }
            fs.Seek(-8 - zipLen, SeekOrigin.End);
            using (var outFs = File.Create(zipPath))
            {
                var buf = new byte[1024 * 64];
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

    private static Icon MakeAppIcon()
    {
        var bmp = new Bitmap(32, 32);
        using (var g = Graphics.FromImage(bmp))
        {
            g.SmoothingMode = SmoothingMode.AntiAlias;
            g.Clear(Color.FromArgb(18, 16, 14));
            using (var brush = new SolidBrush(Gold))
            {
                g.FillEllipse(brush, 4, 4, 24, 24);
            }
            using (var pen = new Pen(Color.FromArgb(26, 19, 8), 2.2f))
            {
                g.DrawArc(pen, 10, 11, 12, 12, 200, 140);
                g.DrawArc(pen, 13, 14, 6, 6, 200, 140);
            }
            g.FillEllipse(new SolidBrush(Color.FromArgb(26, 19, 8)), 15, 20, 3, 3);
        }
        IntPtr h = bmp.GetHicon();
        Icon ico = Icon.FromHandle(h);
        return (Icon)ico.Clone();
    }

    [STAThread]
    public static void Main()
    {
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);
        Application.Run(new SetupForm());
    }
}

internal sealed class BrandPanel : Panel
{
    public BrandPanel()
    {
        DoubleBuffered = true;
        BackColor = Color.FromArgb(28, 24, 20);
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        base.OnPaint(e);
        var g = e.Graphics;
        g.SmoothingMode = SmoothingMode.AntiAlias;
        using (var bg = new LinearGradientBrush(ClientRectangle, Color.FromArgb(42, 32, 20), Color.FromArgb(18, 16, 14), 90f))
        {
            g.FillRectangle(bg, ClientRectangle);
        }
        using (var accent = new SolidBrush(Color.FromArgb(232, 176, 88)))
        {
            g.FillRectangle(accent, 0, 0, 6, Height);
        }
        using (var gold = new SolidBrush(Color.FromArgb(232, 176, 88)))
        using (var cream = new SolidBrush(Color.FromArgb(250, 244, 232)))
        using (var muted = new SolidBrush(Color.FromArgb(196, 184, 164)))
        using (var titleFont = new Font("Segoe UI", 16f, FontStyle.Bold))
        using (var small = new Font("Segoe UI", 9f))
        using (var tiny = new Font("Segoe UI", 8.5f))
        {
            g.FillEllipse(gold, 36, 48, 56, 56);
            using (var pen = new Pen(Color.FromArgb(26, 19, 8), 3f))
            {
                g.DrawArc(pen, 48, 60, 32, 32, 200, 140);
                g.DrawArc(pen, 54, 66, 20, 20, 200, 140);
            }
            g.FillEllipse(new SolidBrush(Color.FromArgb(26, 19, 8)), 61, 88, 6, 6);
            g.DrawString("Wi-Fi da loja", titleFont, cream, 28, 124);
            g.DrawString("Assistente de instalação", small, muted, 28, 158);
            int y = 220;
            DrawCheck(g, gold, cream, 28, y, "Painel completo");
            DrawCheck(g, gold, cream, 28, y + 36, "PHP incluído");
            DrawCheck(g, gold, cream, 28, y + 72, "Atalho e bandeja");
            g.DrawString("Windows 10 / 11  ·  v1.0", tiny, muted, 28, Height - 64);
        }
    }

    private static void DrawCheck(Graphics g, Brush gold, Brush cream, int x, int y, string text)
    {
        g.FillEllipse(gold, x, y, 18, 18);
        using (var font = new Font("Segoe UI", 9f, FontStyle.Bold))
        {
            g.DrawString("✓", font, new SolidBrush(Color.FromArgb(26, 19, 8)), x + 2, y);
            g.DrawString(text, new Font("Segoe UI", 9.5f), cream, x + 28, y);
        }
    }
}
