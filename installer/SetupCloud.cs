using System;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.IO;
using System.IO.Compression;
using System.Reflection;
using System.Windows.Forms;

internal sealed class SetupCloudForm : Form
{
    private static readonly Color Bg = Color.FromArgb(11, 15, 20);
    private static readonly Color Card = Color.FromArgb(20, 27, 34);
    private static readonly Color Ink = Color.FromArgb(238, 243, 248);
    private static readonly Color Muted = Color.FromArgb(141, 154, 171);
    private static readonly Color Gold = Color.FromArgb(232, 176, 88);

    private readonly TextBox pathBox;
    private readonly TextBox urlBox;
    private readonly TextBox tokenBox;
    private readonly TextBox logBox;
    private readonly Label statusLabel;
    private readonly ProgressBar bar;
    private readonly Button installBtn;
    private bool installing;

    public SetupCloudForm()
    {
        Text = "Wi-Fi da Loja — Agente Windows";
        Width = 720;
        Height = 520;
        StartPosition = FormStartPosition.CenterScreen;
        FormBorderStyle = FormBorderStyle.FixedSingle;
        MaximizeBox = false;
        BackColor = Bg;
        ForeColor = Ink;
        Font = new Font("Segoe UI", 10f);

        Controls.Add(new Label
        {
            Left = 24,
            Top = 20,
            Width = 660,
            Height = 28,
            Text = "Instalar agente (modo nuvem)",
            Font = new Font("Segoe UI", 16f, FontStyle.Bold),
            ForeColor = Ink
        });
        Controls.Add(new Label
        {
            Left = 24,
            Top = 52,
            Width = 660,
            Height = 44,
            Text = "Pacote leve: bandeja, hotspot e DNS cativo. Sem PHP ou MySQL local — o portal fica no painel central.",
            ForeColor = Muted
        });

        Controls.Add(Lbl("Pasta de instalação", 24, 108));
        pathBox = new TextBox
        {
            Left = 24,
            Top = 128,
            Width = 520,
            Height = 28,
            Text = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "WiFiDaLoja"),
            BackColor = Card,
            ForeColor = Ink,
            BorderStyle = BorderStyle.FixedSingle
        };
        var browse = new Button { Left = 552, Top = 126, Width = 100, Height = 30, Text = "Procurar…" };
        browse.Click += delegate
        {
            using (var dlg = new FolderBrowserDialog())
            {
                if (dlg.ShowDialog() == DialogResult.OK && dlg.SelectedPath.Length > 0)
                {
                    pathBox.Text = dlg.SelectedPath;
                }
            }
        };

        Controls.Add(Lbl("URL do painel (https://...)", 24, 168));
        urlBox = new TextBox
        {
            Left = 24,
            Top = 188,
            Width = 628,
            Height = 28,
            BackColor = Card,
            ForeColor = Ink,
            BorderStyle = BorderStyle.FixedSingle
        };

        Controls.Add(Lbl("Token do hotspot (Painel → Hotspots → Abrir)", 24, 228));
        tokenBox = new TextBox
        {
            Left = 24,
            Top = 248,
            Width = 628,
            Height = 28,
            BackColor = Card,
            ForeColor = Ink,
            BorderStyle = BorderStyle.FixedSingle
        };

        bar = new ProgressBar { Left = 24, Top = 292, Width = 628, Height = 10 };
        statusLabel = new Label { Left = 24, Top = 308, Width = 628, Height = 22, ForeColor = Muted, Text = "Pronto para instalar." };

        logBox = new TextBox
        {
            Left = 24,
            Top = 336,
            Width = 628,
            Height = 72,
            Multiline = true,
            ReadOnly = true,
            ScrollBars = ScrollBars.Vertical,
            BackColor = Card,
            ForeColor = Muted,
            Font = new Font("Consolas", 8.5f),
            Visible = false
        };

        installBtn = new Button
        {
            Left = 24,
            Top = 420,
            Width = 140,
            Height = 36,
            Text = "Instalar",
            FlatStyle = FlatStyle.Flat,
            BackColor = Gold,
            ForeColor = Color.FromArgb(26, 19, 8),
            Font = new Font("Segoe UI", 9f, FontStyle.Bold)
        };
        installBtn.Click += delegate { RunInstall(); };

        var cancel = new Button
        {
            Left = 172,
            Top = 420,
            Width = 100,
            Height = 36,
            Text = "Cancelar",
            FlatStyle = FlatStyle.Flat,
            BackColor = Card,
            ForeColor = Ink
        };
        cancel.Click += delegate { Close(); };

        Controls.Add(pathBox);
        Controls.Add(browse);
        Controls.Add(urlBox);
        Controls.Add(tokenBox);
        Controls.Add(bar);
        Controls.Add(statusLabel);
        Controls.Add(logBox);
        Controls.Add(installBtn);
        Controls.Add(cancel);
    }

    private static Label Lbl(string text, int x, int y)
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
        if (dest.Length < 8)
        {
            MessageBox.Show("Escolha uma pasta de instalação válida.", Text);
            return;
        }
        if (panelUrl.Length < 8 || token.Length < 8)
        {
            MessageBox.Show("Informe a URL do painel e o token do hotspot.", Text);
            return;
        }
        installing = true;
        installBtn.Enabled = false;
        try
        {
            statusLabel.Text = "Extraindo arquivos…";
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
            Log(output);
            if (p.ExitCode != 0)
            {
                throw new Exception("Falha na configuração do Windows. Veja os detalhes abaixo.");
            }
            bar.Value = 100;
            statusLabel.Text = "Instalação concluída.";
            installBtn.Text = "Fechar";
            installBtn.Enabled = true;
            MessageBox.Show("Agente instalado.\nO ícone na bandeja deve sincronizar com o painel em alguns segundos.", "Concluído", MessageBoxButtons.OK, MessageBoxIcon.Information);
        }
        catch (Exception ex)
        {
            installing = false;
            logBox.Visible = true;
            Log("Erro: " + ex.Message);
            statusLabel.Text = ex.Message;
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
                throw new Exception("Pacote inválido. Gere de novo com installer\\Empacotar-Cloud.ps1");
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

    [STAThread]
    public static void Main()
    {
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);
        Application.Run(new SetupCloudForm());
    }
}
