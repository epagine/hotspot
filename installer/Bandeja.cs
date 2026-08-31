using System;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Windows.Forms;

internal sealed class TrayApp : ApplicationContext
{
    private readonly string root;
    private readonly NotifyIcon icon;
    private readonly Timer timer;

    public TrayApp()
    {
        root = AppDomain.CurrentDomain.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar);
        icon = new NotifyIcon
        {
            Icon = SystemIcons.Information,
            Visible = true,
            Text = "Wi-Fi da loja"
        };
        icon.DoubleClick += delegate { OpenPanel(); };
        icon.ContextMenu = new ContextMenu(new[]
        {
            new MenuItem("Abrir painel", delegate { OpenPanel(); }),
            new MenuItem("Ligar rede", delegate { WriteCommand("start"); }),
            new MenuItem("Desligar rede", delegate { WriteCommand("stop"); }),
            new MenuItem("Vincular ao painel central", delegate { BindStore(); }),
            new MenuItem("-"),
            new MenuItem("Encerrar", delegate { ExitApp(); })
        });
        StartBackend();
        timer = new Timer { Interval = 4000 };
        timer.Tick += delegate { UpdateTip(); };
        timer.Start();
        UpdateTip();
    }

    private string Storage(string name)
    {
        return Path.Combine(root, "storage", name);
    }

    private void StartBackend()
    {
        Directory.CreateDirectory(Path.Combine(root, "storage"));
        string agent = Path.Combine(root, "scripts", "agente-hotspot.ps1");
        string panel = Path.Combine(root, "scripts", "iniciar-painel.ps1");
        StartHidden("powershell.exe", "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"" + agent + "\"");
        StartHidden("powershell.exe", "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"" + panel + "\" -NoBrowser");
    }

    private static void StartHidden(string file, string args)
    {
        try
        {
            Process.Start(new ProcessStartInfo
            {
                FileName = file,
                Arguments = args,
                UseShellExecute = false,
                CreateNoWindow = true,
                WindowStyle = ProcessWindowStyle.Hidden
            });
        }
        catch
        {
        }
    }

    private void WriteCommand(string action)
    {
        string id = Guid.NewGuid().ToString("N").Substring(0, 16);
        string json = "{\n  \"id\": \"" + id + "\",\n  \"action\": \"" + action + "\",\n  \"at\": \"" + DateTime.Now.ToString("o") + "\"\n}\n";
        File.WriteAllText(Storage("command.json"), json);
        icon.ShowBalloonTip(2500, "Wi-Fi da loja", action == "start" ? "Ligando a rede..." : "Desligando a rede...", ToolTipIcon.Info);
    }

    private void BindStore()
    {
        using (var f = new Form())
        {
            f.Text = "Vincular loja";
            f.Width = 480;
            f.Height = 220;
            f.FormBorderStyle = FormBorderStyle.FixedDialog;
            f.StartPosition = FormStartPosition.CenterScreen;
            f.MaximizeBox = false;
            var url = new TextBox { Left = 20, Top = 40, Width = 420, Text = ReadCloud("panel_url") };
            var token = new TextBox { Left = 20, Top = 100, Width = 420, Text = ReadCloud("token") };
            f.Controls.Add(new Label { Left = 20, Top = 18, Width = 420, Text = "Endereço do painel (https://...)" });
            f.Controls.Add(url);
            f.Controls.Add(new Label { Left = 20, Top = 78, Width = 420, Text = "Token da loja" });
            f.Controls.Add(token);
            var ok = new Button { Text = "Salvar", Left = 260, Top = 140, Width = 80, DialogResult = DialogResult.OK };
            var cancel = new Button { Text = "Cancelar", Left = 350, Top = 140, Width = 90, DialogResult = DialogResult.Cancel };
            f.Controls.Add(ok);
            f.Controls.Add(cancel);
            f.AcceptButton = ok;
            f.CancelButton = cancel;
            if (f.ShowDialog() != DialogResult.OK) return;
            string u = url.Text.Trim().TrimEnd('/');
            string t = token.Text.Trim();
            if (u.Length < 8 || t.Length < 8)
            {
                MessageBox.Show("Informe o endereço do painel e o token da loja.", "Wi-Fi da loja");
                return;
            }
            string json = "{\n  \"panel_url\": \"" + u.Replace("\\", "\\\\").Replace("\"", "\\\"") + "\",\n  \"token\": \"" + t.Replace("\\", "\\\\").Replace("\"", "\\\"") + "\"\n}\n";
            Directory.CreateDirectory(Path.Combine(root, "storage"));
            File.WriteAllText(Storage("cloud.json"), json);
            icon.ShowBalloonTip(2500, "Wi-Fi da loja", "Loja vinculada ao painel central.", ToolTipIcon.Info);
        }
    }

    private string ReadCloud(string key)
    {
        try
        {
            string raw = File.Exists(Storage("cloud.json")) ? File.ReadAllText(Storage("cloud.json")) : "";
            string needle = "\"" + key + "\"";
            int i = raw.IndexOf(needle, StringComparison.OrdinalIgnoreCase);
            if (i < 0) return "";
            int colon = raw.IndexOf(':', i);
            int q1 = raw.IndexOf('"', colon + 1);
            int q2 = raw.IndexOf('"', q1 + 1);
            if (q1 < 0 || q2 < 0) return "";
            return raw.Substring(q1 + 1, q2 - q1 - 1);
        }
        catch
        {
            return "";
        }
    }

    private void OpenPanel()
    {
        string url = ReadCloud("panel_url");
        if (url.Length > 8)
        {
            url = url.TrimEnd('/') + "/admin";
        }
        else
        {
            string cfg = Path.Combine(root, "app", "config.php");
            url = File.Exists(cfg) ? "http://127.0.0.1:8080/admin" : "http://127.0.0.1:8080/instalar";
        }
        try { Process.Start(url); } catch { Process.Start(new ProcessStartInfo { FileName = url, UseShellExecute = true }); }
    }

    private void UpdateTip()
    {
        try
        {
            string path = Storage("status.json");
            if (!File.Exists(path))
            {
                icon.Text = "Wi-Fi da loja · iniciando";
                return;
            }
            string raw = File.ReadAllText(path);
            bool on = raw.IndexOf("\"hotspot_on\":  true", StringComparison.OrdinalIgnoreCase) >= 0
                || raw.IndexOf("\"hotspot_on\":true", StringComparison.OrdinalIgnoreCase) >= 0;
            string slots = "0/8";
            int w = raw.IndexOf("\"windows_clients\"", StringComparison.OrdinalIgnoreCase);
            if (w >= 0)
            {
                string tail = raw.Substring(w);
                int colon = tail.IndexOf(':');
                if (colon > 0)
                {
                    string num = "";
                    for (int i = colon + 1; i < tail.Length; i++)
                    {
                        char c = tail[i];
                        if (char.IsDigit(c)) num += c;
                        else if (num.Length > 0) break;
                    }
                    if (num.Length > 0) slots = num + "/8";
                }
            }
            icon.Text = (on ? "Rede ligada" : "Rede desligada") + " · " + slots;
        }
        catch
        {
            icon.Text = "Wi-Fi da loja";
        }
    }

    private void ExitApp()
    {
        icon.Visible = false;
        Application.Exit();
    }

    [STAThread]
    public static void Main()
    {
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);
        Application.Run(new TrayApp());
    }
}
