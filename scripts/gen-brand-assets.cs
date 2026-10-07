using System;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.Drawing.Text;
using System.IO;

internal static class BrandAssets
{
    private static Bitmap Mark(int size)
    {
        Bitmap bmp = new Bitmap(size, size);
        using (Graphics g = Graphics.FromImage(bmp))
        {
            g.SmoothingMode = SmoothingMode.AntiAlias;
            g.InterpolationMode = InterpolationMode.HighQualityBicubic;
            g.TextRenderingHint = TextRenderingHint.AntiAliasGridFit;
            g.Clear(Color.Transparent);

            float s = size / 512f;

            // rounded red tile
            using (GraphicsPath path = new GraphicsPath())
            {
                float r = 112 * s, d = 512 * s;
                path.AddArc(0, 0, r, r, 180, 90);
                path.AddArc(d - r, 0, r, r, 270, 90);
                path.AddArc(d - r, d - r, r, r, 0, 90);
                path.AddArc(0, d - r, r, r, 90, 90);
                path.CloseFigure();
                using (LinearGradientBrush br = new LinearGradientBrush(
                    new RectangleF(0, 0, d, d),
                    Color.FromArgb(239, 68, 68),
                    Color.FromArgb(185, 28, 28), 45f))
                {
                    g.FillPath(br, path);
                }
            }

            using (Pen white = new Pen(Color.White, 22 * s))
            {
                white.LineJoin = LineJoin.Round;
                float w = 170 * s, h = 252 * s, x = 130 * s, y = 140 * s;
                g.DrawRectangle(white, x, y, w, h);
            }

            using (Brush b = new SolidBrush(Color.White))
            {
                g.FillRectangle(b, 146 * s, 276 * s, 150 * s, 26 * s);
                using (Brush b2 = new SolidBrush(Color.FromArgb(217, Color.White)))
                    g.FillRectangle(b2, 146 * s, 212 * s, 118 * s, 16 * s);
                g.FillEllipse(b, 339 * s, 183 * s, 26 * s, 26 * s);

                // lightning bolt
                PointF[] bolt = new PointF[]
                {
                    new PointF(232*s,132*s), new PointF(188*s,240*s), new PointF(228*s,240*s),
                    new PointF(206*s,332*s), new PointF(264*s,204*s), new PointF(224*s,204*s)
                };
                g.FillPolygon(b, bolt);
            }
        }
        return bmp;
    }

    private static void SavePng(Bitmap bmp, string path, int w, int h)
    {
        using (Bitmap outp = new Bitmap(w, h))
        using (Graphics g = Graphics.FromImage(outp))
        {
            g.InterpolationMode = InterpolationMode.HighQualityBicubic;
            g.SmoothingMode = SmoothingMode.AntiAlias;
            g.TextRenderingHint = TextRenderingHint.AntiAliasGridFit;
            g.Clear(Color.FromArgb(11, 12, 16));

            // subtle vignette so it does not read as a flat black rectangle
            using (LinearGradientBrush bg = new LinearGradientBrush(
                new Rectangle(0, 0, w, h),
                Color.FromArgb(20, 22, 28), Color.FromArgb(8, 9, 12), 90f))
            {
                g.FillRectangle(bg, 0, 0, w, h);
            }

            using (Brush glow = new SolidBrush(Color.FromArgb(38, 239, 68, 68)))
                g.FillEllipse(glow, w / 2 - (int)(0.55 * h), h - (int)(0.5 * h), (int)(1.1 * h), (int)(1.1 * h));

            int markSize = (int)(h * 0.52);
            using (Bitmap mark = Mark(markSize))
                g.DrawImage(mark, (w - markSize) / 2, (int)(h * 0.16), markSize, markSize);

            // wordmark
            string brand = "PCTechGuy Online";
            string sub = "Custom gaming PCs — built and tested in the UK";

            using (Font fb = new Font("Segoe UI", h * 0.085f, FontStyle.Bold, GraphicsUnit.Pixel))
            using (SolidBrush tb = new SolidBrush(Color.White))
            {
                StringFormat sf = new StringFormat { Alignment = StringAlignment.Center };
                g.DrawString(brand, fb, tb, new PointF(w / 2f, h * 0.735f - h * 0.045f), sf);
            }

            using (Font fs = new Font("Segoe UI", h * 0.043f, FontStyle.Regular, GraphicsUnit.Pixel))
            using (SolidBrush tb2 = new SolidBrush(Color.FromArgb(190, 200, 210)))
            {
                StringFormat sf2 = new StringFormat { Alignment = StringAlignment.Center };
                g.DrawString(sub, fs, tb2, new PointF(w / 2f, h * 0.80f), sf2);
            }

            outp.Save(path, ImageFormat.Png);
        }
    }

    private static byte[] PngBytes(Bitmap bmp)
    {
        using (MemoryStream t = new MemoryStream())
        {
            bmp.Save(t, ImageFormat.Png);
            return t.ToArray();
        }
    }

    private static void SaveIco(string path, int[] sizes)
    {
        byte[][] pngs = new byte[sizes.Length][];
        for (int i = 0; i < sizes.Length; i++)
        {
            using (Bitmap b = Mark(sizes[i])) pngs[i] = PngBytes(b);
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

        SavePng(null, Path.Combine(dir, "pctg-og.png"), 1200, 630);
        using (Bitmap m180 = Mark(180)) m180.Save(Path.Combine(dir, "pctg-mark-180.png"), ImageFormat.Png);
        using (Bitmap m512 = Mark(512)) m512.Save(Path.Combine(dir, "pctg-mark-512.png"), ImageFormat.Png);
        SaveIco(Path.Combine(dir, "..", "..", "favicon.ico"), new int[] { 16, 32, 48 });

        Console.WriteLine("brand assets written to " + dir);
        return 0;
    }
}