using System;
using System.Diagnostics;
using System.IO;
using System.Windows.Forms;

internal static class Program
{
    [STAThread]
    private static int Main()
    {
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);

        string root = AppDomain.CurrentDomain.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar);
        string ps1 = Path.Combine(root, "scripts", "instalar-windows.ps1");
        if (!File.Exists(ps1))
        {
            MessageBox.Show(
                "Não achei scripts\\instalar-windows.ps1. Coloque o instalador na pasta do sistema (junto de index.php).",
                "Wi-Fi da loja",
                MessageBoxButtons.OK,
                MessageBoxIcon.Error);
            return 1;
        }

        var info = new ProcessStartInfo
        {
            FileName = "powershell.exe",
            Arguments = "-NoProfile -ExecutionPolicy Bypass -File \"" + ps1 + "\"",
            UseShellExecute = true,
            Verb = "runas",
            WorkingDirectory = root
        };

        try
        {
            Process p = Process.Start(info);
            if (p == null)
            {
                return 1;
            }
            p.WaitForExit();
            return p.ExitCode;
        }
        catch (System.ComponentModel.Win32Exception)
        {
            MessageBox.Show("A instalação precisa da permissão de administrador.", "Wi-Fi da loja", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            return 2;
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Wi-Fi da loja", MessageBoxButtons.OK, MessageBoxIcon.Error);
            return 1;
        }
    }
}
