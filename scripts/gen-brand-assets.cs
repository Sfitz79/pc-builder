using System;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.Drawing.Text;
using System.IO;

internal static class BrandAssets
{
    private static byte[] PngBytes(Bitmap bmp)
    {
        using (MemoryStream t = new MemoryStream())
        {
            bmp.Save(t, ImageFormat.Png);
            return t.ToArray();
        }
    }

    // Favicon straight from the Boss's real logo: white is keyed out so the
    // transparent favicon.ico has no white box, then the darkest pixels are
    // lifted to white so the mark still reads on a light browser tab.
    private static Bitmap FaviconSource(string logoPath)
    {
        using (Bitmap input = new Bitmap(logoPath))
        {
            int w = input.Width, h = input.Height;
            Bitmap outp = new Bitmap(w, h, PixelFormat.Format32bppArgb);
            using (Graphics g = Graphics.FromImage(outp))
            {
                g.Clear(Color.Transparent);
                g.DrawImage(input, new Rectangle(0, 0, w, h));
            }
            for (int y = 0; y < h; y++)
            {
                for (int x = 0; x < w; x++)
                {
                    Color p = outp.GetPixel(x, y);
                    int mx = Math.Max(p.R, Math.Max(p.G, p.B));
                    int mn = Math.Min(p.R, Math.Min(p.G, p.B));
                    if (p.R >= 232 && p.G >= 232 && p.B >= 232 && (mx - mn) <= 18)
                    {
                        outp.SetPixel(x, y, Color.FromArgb(0, p.R, p.G, p.B));
                        continue;
                    }
                    double lum = 0.2126 * p.R + 0.7152 * p.G + 0.0722 * p.B;
                    if (lum < 96)
                    {
                        int t = (int)Math.Round(Math.Min(1.0, (96 - lum) / 96.0) * 0.92);
                        outp.SetPixel(x, y, Color.FromArgb(p.A,
                            (int)Math.Min(255, p.R + (245 - p.R) * t),
                            (int)Math.Min(255, p.G + (245 - p.G) * t),
                            (int)Math.Min(255, p.B + (245 - p.B) * t)));
                    }
                }
            }
            return outp;
        }
    }

    private static void SaveIcoFrom(string path, Bitmap src, int[] sizes)
    {
        byte[][] pngs = new byte[sizes.Length][];
        for (int i = 0; i < sizes.Length; i++)
        {
            using (Bitmap b = new Bitmap(sizes[i], sizes[i]))
            {
                using (Graphics g = Graphics.FromImage(b))
                {
                    g.Clear(Color.Transparent);
                    g.InterpolationMode = System.Drawing.Drawing2D.InterpolationMode.HighQualityBicubic;
                    g.DrawImage(src, new Rectangle(0, 0, sizes[i], sizes[i]));
                }
                pngs[i] = PngBytes(b);
            }
        }

        using (MemoryStream ms = new MemoryStream())
        using (BinaryWriter bw = new BinaryWriter(ms))
        {
            bw.Write((ushort)0); bw.Write((ushort)1); bw.Write((ushort)sizes.Length);
            int offset = 6 + 16 * sizes.Length;
            for (int i = 0; i < sizes.Length; i++)
            {
                bw.Write((byte)(sizes[i] >= 256 ? 0 : sizes[i]));
                bw.Write((byte)(sizes[i] >= 256 ? 0 : sizes[i]));
                bw.Write((byte)0); bw.Write((byte)0);
                bw.Write((ushort)1); bw.Write((ushort)32);
                bw.Write((uint)pngs[i].Length);
                bw.Write((uint)offset);
                offset += pngs[i].Length;
            }
            foreach (byte[] p in pngs) bw.Write(p);
            bw.Flush();
            File.WriteAllBytes(path, ms.ToArray());
        }
    }

private static int Main(string[] args)
    {
        string dir = args.Length > 0 ? args[0] : "public/img/brand";
        Directory.CreateDirectory(dir);

        // The OG card is composed from the REAL brand lockup and tagline when
        // they are present, so the preview image a customer sees when the link
        // is shared on WhatsApp or Facebook is the actual branding rather than
        // a generated approximation.
        string logo = Path.Combine(dir, "pctg-logo.png");
        string tagline = Path.Combine(dir, "pctg-tagline.png");

        if (File.Exists(logo) && File.Exists(tagline))
        {
            using (Bitmap logoBmp = new Bitmap(logo))
            using (Bitmap tagBmp = new Bitmap(tagline))
            using (Bitmap card = new Bitmap(1200, 630))
            using (Graphics g = Graphics.FromImage(card))
            {
                g.InterpolationMode = System.Drawing.Drawing2D.InterpolationMode.HighQualityBicubic;
                g.SmoothingMode = System.Drawing.Drawing2D.SmoothingMode.AntiAlias;
                g.TextRenderingHint = TextRenderingHint.AntiAliasGridFit;
                g.Clear(Color.FromArgb(11, 12, 16));

                using (LinearGradientBrush bg = new LinearGradientBrush(
                    new Rectangle(0, 0, 1200, 630),
                    Color.FromArgb(22, 24, 31), Color.FromArgb(8, 9, 12), 65f))
                {
                    g.FillRectangle(bg, 0, 0, 1200, 630);
                }

                // Soft radial bloom rather than a hard-edged filled circle.
                // Kept faint: at higher alpha the concentric passes banded into
                // visible rings that competed with the logo for attention.
                for (int i = 10; i >= 1; i--)
                {
                    int rr = 240 + i * 34;
                    int alpha = (int)Math.Round(7.0 / i);
                    if (alpha < 1) continue;
                    using (Brush glow = new SolidBrush(Color.FromArgb(alpha, 239, 68, 68)))
                        g.FillEllipse(glow, 600 - rr, 330 - rr, rr * 2, rr * 2);
                }

                int lw = 380;
                int lh = (int)Math.Round(logoBmp.Height * (lw / (double)logoBmp.Width));
                g.DrawImage(logoBmp, new Rectangle((1200 - lw) / 2, 96, lw, lh),
                            new Rectangle(0, 0, logoBmp.Width, logoBmp.Height), GraphicsUnit.Pixel);

                int tw = 620;
                int th = (int)Math.Round(tagBmp.Height * (tw / (double)tagBmp.Width));
                g.DrawImage(tagBmp, new Rectangle((1200 - tw) / 2, 96 + lh + 34, tw, th),
                            new Rectangle(0, 0, tagBmp.Width, tagBmp.Height), GraphicsUnit.Pixel);

                using (Font fb = new Font("Segoe UI", 30f, FontStyle.Bold, GraphicsUnit.Pixel))
                using (SolidBrush tb = new SolidBrush(Color.White))
                using (StringFormat sf = new StringFormat { Alignment = StringAlignment.Center })
                {
                    g.DrawString("Custom gaming PCs, built and tested in the UK",
                                 fb, tb, new PointF(600f, 528f), sf);
                }

                card.Save(Path.Combine(dir, "pctg-og.png"), ImageFormat.Png);
                Console.Error.WriteLine("pctg-og.png composed from the real logo + tagline");
            }

            using (Bitmap fav = FaviconSource(Path.Combine(dir, "pctg-mark-512.png")))
            {
                SaveIcoFrom(Path.Combine(dir, "..", "..", "favicon.ico"), fav, new int[] { 16, 32, 48 });
                using (Bitmap f180 = new Bitmap(180, 180))
                using (Graphics g = Graphics.FromImage(f180))
                {
                    g.Clear(Color.Transparent);
                    g.DrawImage(fav, new Rectangle(0, 0, 180, 180));
                    f180.Save(Path.Combine(dir, "pctg-mark-180.png"), ImageFormat.Png);
                }
            }
        }
        else
        {
            Console.Error.WriteLine("real logo/tagline not found - run prep-boss-assets.cs first");
            return 1;
        }

        Console.Error.WriteLine("brand assets written to " + dir);
        return 0;
    }
}
